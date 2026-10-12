<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Addressing\Model\CountryInterface;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Addressing\Model\ZoneMemberInterface;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Customer\Model\CustomerGroupInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Product\Factory\ProductFactoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Entity\AdminOrderCreationInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Factory\CompanyMemberFactoryInterface;
use Webmozart\Assert\Assert;

/**
 * Data created by the tests themselves (inside their rolled-back transaction), so that no test
 * depends on the random Sylius fixtures: companies, members, customers, a zone, shipping and
 * payment methods, a product, and orders placed through the real checkout state machine.
 */
final class TestCatalog
{
    public readonly string $suffix;

    /** Unique part of generated e-mails and codes within a test. */
    private int $sequence = 0;

    private ?ShippingMethodInterface $shippingMethod = null;

    private ?PaymentMethodInterface $paymentMethod = null;

    private ?ProductVariantInterface $variant = null;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->suffix = strtolower(bin2hex(random_bytes(4)));
    }

    public function channel(): ChannelInterface
    {
        $repository = $this->entityManager()->getRepository(ChannelInterface::class);
        $channel = $repository->findOneBy(['hostname' => 'localhost']) ?? $repository->findOneBy(['enabled' => true]);
        Assert::isInstanceOf($channel, ChannelInterface::class);

        return $channel;
    }

    /** Makes a locale available in the channel (created if needed). */
    public function channelLocale(string $code): void
    {
        $channel = $this->channel();
        $locale = $this->entityManager()->getRepository(LocaleInterface::class)->findOneBy(['code' => $code]);
        if (null === $locale) {
            /** @var FactoryInterface<LocaleInterface> $factory */
            $factory = $this->container->get('sylius.factory.locale');
            $locale = $factory->createNew();
            $locale->setCode($code);
            $this->entityManager()->persist($locale);
        }
        Assert::isInstanceOf($locale, LocaleInterface::class);
        $channel->addLocale($locale);
        $this->entityManager()->flush();
    }

    public function localeCode(): string
    {
        return (string) $this->channel()->getDefaultLocale()?->getCode();
    }

    /** A customer with an enabled, verified shop account (password `sylius`), or without account. */
    public function customer(string $name = 'buyer', bool $withAccount = true, string $firstName = 'Ada', string $lastName = 'Lovelace'): CustomerInterface
    {
        /** @var FactoryInterface<CustomerInterface> $customerFactory */
        $customerFactory = $this->container->get('sylius.factory.customer');
        $customer = $customerFactory->createNew();
        $customer->setEmail(sprintf('%s-%s@company-accounts.test', $name, $this->suffix));
        $customer->setFirstName($firstName);
        $customer->setLastName($lastName);
        $customer->setPhoneNumber('+33 6 12 34 56 78');
        if ($withAccount) {
            /** @var FactoryInterface<ShopUserInterface> $userFactory */
            $userFactory = $this->container->get('sylius.factory.shop_user');
            $user = $userFactory->createNew();
            $user->setPlainPassword('sylius');
            $user->setEnabled(true);
            $user->setVerifiedAt(new \DateTime());
            $customer->setUser($user);
        }
        $this->entityManager()->persist($customer);
        $this->entityManager()->flush();

        return $customer;
    }

    public function company(string $name = 'Acme', bool $enabled = true, ?CustomerGroupInterface $group = null): CompanyInterface
    {
        $company = $this->companyDraft($name, $enabled, $group);
        $this->entityManager()->persist($company);
        $this->entityManager()->flush();

        return $company;
    }

    /** A company not persisted yet. */
    public function companyDraft(string $name = 'Acme', bool $enabled = true, ?CustomerGroupInterface $group = null): CompanyInterface
    {
        /** @var FactoryInterface<CompanyInterface> $factory */
        $factory = $this->container->get('tmonier_company_accounts.factory.company');
        $company = $factory->createNew();
        $company->setCode(strtoupper(sprintf('%s_%s_%d', $name, $this->suffix, $this->next())));
        $company->setName($name . ' ' . $this->suffix);
        $company->setVatNumber('FR40123456824');
        $company->setRegistrationNumber('123 456 824');
        $company->setEnabled($enabled);
        $company->setDefaultCustomerGroup($group);

        return $company;
    }

    public function member(CompanyInterface $company, ?CustomerInterface $customer = null, string $role = CompanyMemberInterface::ROLE_BUYER, bool $enabled = true): CompanyMemberInterface
    {
        /** @var CompanyMemberFactoryInterface $factory */
        $factory = $this->container->get('tmonier_company_accounts.factory.company_member');
        $member = $factory->createForCustomer($company, $customer ?? $this->customer($role . '-' . $this->next()), $role);
        $member->setEnabled($enabled);
        $this->entityManager()->persist($member);
        $this->entityManager()->flush();

        return $member;
    }

    /** A membership not persisted yet. */
    public function memberDraft(CompanyInterface $company, CustomerInterface $customer, string $role = CompanyMemberInterface::ROLE_BUYER): CompanyMemberInterface
    {
        /** @var CompanyMemberFactoryInterface $factory */
        $factory = $this->container->get('tmonier_company_accounts.factory.company_member');

        return $factory->createForCustomer($company, $customer, $role);
    }

    /** Limits of a member, in minor units of the channel base currency unless another one is given. */
    public function limit(CompanyMemberInterface $member, ?int $orderLimit, ?int $approvalLimit = null, ?string $currencyCode = null): MemberLimitInterface
    {
        /** @var MemberLimitRepositoryInterface $repository */
        $repository = $this->container->get('tmonier_purchase_approval.repository.member_limit');
        /** @var FactoryInterface<MemberLimitInterface> $factory */
        $factory = $this->container->get('tmonier_purchase_approval.factory.member_limit');
        $limit = $repository->findOneByMember($member) ?? $factory->createNew();
        $limit->setMember($member);
        $limit->setOrderLimit($orderLimit);
        $limit->setApprovalLimit($approvalLimit);
        $limit->setCurrencyCode($currencyCode ?? $this->currencyCode());
        $this->entityManager()->persist($limit);
        $this->entityManager()->flush();

        return $limit;
    }

    public function currencyCode(): string
    {
        return (string) $this->channel()->getBaseCurrency()?->getCode();
    }

    /**
     * An order of the member completed with the given order limit: the cart total is read first,
     * so that the limit is set relative to it whatever promotions the fixtures hold.
     *
     * @param int $limitDelta order limit = cart total + delta (negative: the order is above it)
     */
    public function orderAgainstLimit(CompanyMemberInterface $member, int $limitDelta, ?int $approvalLimit = null, bool $byStaff = false, bool $asGuest = false): OrderInterface
    {
        $member = $this->managed($member);
        $customer = $member->getCustomer();
        Assert::isInstanceOf($customer, CustomerInterface::class);
        $order = $this->placedOrder($customer, $asGuest, 'payment_selected', $byStaff);
        $this->limit($member, $order->getTotal() + $limitDelta, $approvalLimit);
        $this->apply($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);
        $this->entityManager()->flush();

        return $order;
    }

    public function customerGroup(string $name = 'Wholesale'): CustomerGroupInterface
    {
        /** @var FactoryInterface<CustomerGroupInterface> $factory */
        $factory = $this->container->get('sylius.factory.customer_group');
        $group = $factory->createNew();
        $group->setCode(sprintf('%s_%s_%d', strtolower($name), $this->suffix, $this->next()));
        $group->setName($name);
        $this->entityManager()->persist($group);
        $this->entityManager()->flush();

        return $group;
    }

    /**
     * An order placed through the checkout state machine (completed in the last flush). Signed-in
     * customers get setCustomerWithAuthorization(), as the shop does; `$asGuest` places it as a
     * guest with the e-mail of the customer. `$byStaff` creates it as
     * tmonier/sylius-admin-order-creation-plugin does (customer set without authorization,
     * AdminOrderCreation marker).
     */
    public function placedOrder(CustomerInterface $customer, bool $asGuest = false, string $checkoutState = 'completed', bool $byStaff = false): OrderInterface
    {
        $channel = $this->channel();
        /** @var FactoryInterface<OrderInterface> $orderFactory */
        $orderFactory = $this->container->get('sylius.factory.order');
        $order = $orderFactory->createNew();
        $order->setChannel($channel);
        $order->setLocaleCode($this->localeCode());
        $order->setCurrencyCode((string) $channel->getBaseCurrency()?->getCode());
        if ($asGuest || $byStaff || null === $customer->getUser()) {
            $order->setCustomer($customer);
        } else {
            $order->setCustomerWithAuthorization($customer);
        }
        $order->setTokenValue(bin2hex(random_bytes(10)));

        /** @var FactoryInterface<OrderItemInterface> $itemFactory */
        $itemFactory = $this->container->get('sylius.factory.order_item');
        $item = $itemFactory->createNew();
        $item->setVariant($this->variant());
        /** @var OrderItemQuantityModifierInterface $quantityModifier */
        $quantityModifier = $this->container->get('sylius.modifier.order_item_quantity');
        $quantityModifier->modify($item, 2);
        /** @var OrderModifierInterface $orderModifier */
        $orderModifier = $this->container->get('sylius.modifier.order');
        $orderModifier->addToOrder($order, $item);

        $order->setShippingAddress($this->address());
        $order->setBillingAddress($this->address());
        $this->entityManager()->persist($order);
        if ($byStaff) {
            /** @var FactoryInterface<AdminOrderCreationInterface> $creationFactory */
            $creationFactory = $this->container->get('tmonier_admin_order.factory.admin_order_creation');
            $creation = $creationFactory->createNew();
            $creation->setOrder($order);
            $this->entityManager()->persist($creation);
        }

        $this->shippingMethod();
        $paymentMethod = $this->paymentMethod();
        $this->apply($order, OrderCheckoutTransitions::TRANSITION_ADDRESS);
        $this->apply($order, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING);
        $payment = $order->getLastPayment(PaymentInterface::STATE_CART);
        Assert::notNull($payment);
        $payment->setMethod($paymentMethod);
        $this->apply($order, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);
        $this->entityManager()->flush();
        if ('completed' === $checkoutState) {
            $this->apply($order, OrderCheckoutTransitions::TRANSITION_COMPLETE);
            $this->entityManager()->flush();
        }

        return $order;
    }

    /**
     * Completed orders written directly (no checkout), cheap for lists: the company link is
     * recorded by the flush as for any order completed by a signed-in customer.
     *
     * @return list<OrderInterface>
     */
    public function completedOrders(CustomerInterface $customer, int $count): array
    {
        $channel = $this->channel();
        /** @var FactoryInterface<OrderInterface> $orderFactory */
        $orderFactory = $this->container->get('sylius.factory.order');
        $orders = [];
        for ($i = 0; $i < $count; ++$i) {
            $order = $orderFactory->createNew();
            $order->setChannel($channel);
            $order->setLocaleCode($this->localeCode());
            $order->setCurrencyCode((string) $channel->getBaseCurrency()?->getCode());
            $order->setCustomerWithAuthorization($customer);
            $order->setNumber(sprintf('TC-%s-%02d', $this->suffix, $i));
            $order->setState(OrderInterface::STATE_NEW);
            $order->setCheckoutState('completed');
            $order->setPaymentState('awaiting_payment');
            $order->setShippingState('ready');
            $order->setCheckoutCompletedAt(new \DateTime(sprintf('-%d minutes', $i)));
            $this->entityManager()->persist($order);
            $orders[] = $order;
        }
        $this->entityManager()->flush();

        return $orders;
    }

    public function apply(OrderInterface $order, string $transition): void
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = $this->container->get('sylius_abstraction.state_machine');
        Assert::true($stateMachine->can($order, OrderCheckoutTransitions::GRAPH, $transition), sprintf('Checkout transition "%s" from "%s"', $transition, (string) $order->getCheckoutState()));
        $stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, $transition);
    }

    private function next(): int
    {
        return ++$this->sequence;
    }

    private function address(): AddressInterface
    {
        /** @var FactoryInterface<AddressInterface> $factory */
        $factory = $this->container->get('sylius.factory.address');
        $address = $factory->createNew();
        $address->setFirstName('Ada');
        $address->setLastName('Lovelace');
        $address->setStreet('1 Main Street');
        $address->setCity('Springfield');
        $address->setPostcode('12345');
        $address->setCountryCode('US');
        $address->setPhoneNumber('+1 555 0100');

        return $address;
    }

    /** A flat-rate shipping method of the channel shipping to the US (zone created by the test). */
    private function shippingMethod(): ShippingMethodInterface
    {
        if (null !== $this->shippingMethod) {
            return $this->shippingMethod = $this->managed($this->shippingMethod);
        }

        $channel = $this->channel();
        $country = $this->entityManager()->getRepository(CountryInterface::class)->findOneBy(['code' => 'US']);
        if (!$country instanceof CountryInterface) {
            /** @var FactoryInterface<CountryInterface> $countryFactory */
            $countryFactory = $this->container->get('sylius.factory.country');
            $country = $countryFactory->createNew();
            $country->setCode('US');
            $this->entityManager()->persist($country);
        }
        $country->setEnabled(true);

        /** @var FactoryInterface<ZoneInterface> $zoneFactory */
        $zoneFactory = $this->container->get('sylius.factory.zone');
        /** @var FactoryInterface<ZoneMemberInterface> $memberFactory */
        $memberFactory = $this->container->get('sylius.factory.zone_member');
        $zone = $zoneFactory->createNew();
        $zone->setCode('TMONIER_' . strtoupper($this->suffix));
        $zone->setName((string) $zone->getCode());
        $zone->setType(ZoneInterface::TYPE_COUNTRY);
        $member = $memberFactory->createNew();
        $member->setCode('US');
        $zone->addMember($member);
        $this->entityManager()->persist($zone);

        /** @var FactoryInterface<ShippingMethodInterface> $factory */
        $factory = $this->container->get('sylius.factory.shipping_method');
        $method = $factory->createNew();
        $code = 'tmonier_ship_' . $this->suffix;
        $method->setCode($code);
        $method->setCurrentLocale($this->localeCode());
        $method->setFallbackLocale($this->localeCode());
        $method->setName('Test ' . $code);
        $method->setZone($zone);
        $method->setCalculator('flat_rate');
        $method->setConfiguration([(string) $channel->getCode() => ['amount' => 500]]);
        $method->addChannel($channel);
        $method->setEnabled(true);
        $method->setPosition(-1);
        $this->entityManager()->persist($method);
        $this->entityManager()->flush();

        return $this->shippingMethod = $method;
    }

    private function paymentMethod(): PaymentMethodInterface
    {
        if (null !== $this->paymentMethod) {
            return $this->paymentMethod = $this->managed($this->paymentMethod);
        }

        /** @var PaymentMethodFactoryInterface<PaymentMethodInterface> $factory */
        $factory = $this->container->get('sylius.factory.payment_method');
        $method = $factory->createWithGateway('offline');
        $code = 'tmonier_pay_' . $this->suffix;
        $method->setCode($code);
        $method->getGatewayConfig()?->setGatewayName($code);
        $method->setCurrentLocale($this->localeCode());
        $method->setFallbackLocale($this->localeCode());
        $method->setName('Bank transfer ' . $code);
        $method->addChannel($this->channel());
        $method->setEnabled(true);
        $method->setPosition(-1);
        $this->entityManager()->persist($method);
        $this->entityManager()->flush();

        return $this->paymentMethod = $method;
    }

    /** An enabled, untracked variant of a new product sold in the channel. */
    private function variant(): ProductVariantInterface
    {
        if (null !== $this->variant) {
            return $this->variant = $this->managed($this->variant);
        }

        $channel = $this->channel();
        /** @var ProductFactoryInterface<ProductInterface> $productFactory */
        $productFactory = $this->container->get('sylius.factory.product');
        /** @var FactoryInterface<ChannelPricingInterface> $pricingFactory */
        $pricingFactory = $this->container->get('sylius.factory.channel_pricing');
        $code = 'tmonier_company_' . $this->suffix;

        $product = $productFactory->createWithVariant();
        $product->setCode($code);
        $product->setCurrentLocale($this->localeCode());
        $product->setFallbackLocale($this->localeCode());
        $product->setName('Office chair ' . $this->suffix);
        $product->setSlug($code);
        $product->setEnabled(true);
        $product->addChannel($channel);

        $variant = $product->getVariants()->first();
        Assert::isInstanceOf($variant, ProductVariantInterface::class);
        $variant->setCode($code . '_variant');
        $variant->setCurrentLocale($this->localeCode());
        $variant->setFallbackLocale($this->localeCode());
        $variant->setName('Black');
        $variant->setEnabled(true);
        $variant->setTracked(false);
        $variant->setShippingRequired(true);
        $pricing = $pricingFactory->createNew();
        $pricing->setChannelCode($channel->getCode());
        $pricing->setPrice(2500);
        $pricing->setOriginalPrice(2500);
        $variant->addChannelPricing($pricing);

        $this->entityManager()->persist($product);
        $this->entityManager()->flush();

        return $this->variant = $variant;
    }

    /**
     * Entities cached by the catalog, reloaded after an entity manager clear().
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function managed(object $entity): object
    {
        if ($this->entityManager()->contains($entity) || !method_exists($entity, 'getId')) {
            return $entity;
        }
        // The mapped class, not a Doctrine proxy class (lazy ghosts are not used before PHP 8.4).
        $class = $this->entityManager()->getClassMetadata($entity::class)->getName();
        $reloaded = $this->entityManager()->find($class, $entity->getId());
        Assert::isInstanceOf($reloaded, $class);
        /** @var T $reloaded */

        return $reloaded;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $this->container->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
