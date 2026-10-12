<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Form\Extension;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Form\Type\CompanyMemberType;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\LimitAmount;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\MemberLimitUpdater;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\MemberLimitInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;

/**
 * Purchase limits on the admin member form of company-accounts (own table, unmapped fields):
 * order limit, approval limit and their currency. Both limits empty removes the limits.
 */
final class CompanyMemberLimitsTypeExtension extends AbstractTypeExtension
{
    /**
     * @param RepositoryInterface<CurrencyInterface> $currencyRepository
     * @param FactoryInterface<MemberLimitInterface> $limitFactory
     */
    public function __construct(
        private readonly MemberLimitRepositoryInterface $limitRepository,
        private readonly FactoryInterface $limitFactory,
        private readonly RepositoryInterface $currencyRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [CompanyMemberType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currencies = [];
        foreach ($this->currencyRepository->findAll() as $currency) {
            $code = $currency->getCode();
            if (null !== $code) {
                $currencies[$code] = $code;
            }
        }
        ksort($currencies);

        $builder
            ->add('approvalOrderLimit', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'tmonier_purchase_approval.form.order_limit',
                'help' => 'tmonier_purchase_approval.form.order_limit_help',
                'attr' => ['inputmode' => 'decimal'],
            ])
            ->add('approvalApprovalLimit', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'tmonier_purchase_approval.form.approval_limit',
                'help' => 'tmonier_purchase_approval.form.approval_limit_help',
                'attr' => ['inputmode' => 'decimal'],
            ])
            ->add('approvalCurrency', ChoiceType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'tmonier_purchase_approval.form.limits_currency',
                'choices' => $currencies,
                'placeholder' => 'tmonier_purchase_approval.form.no_currency',
            ])
            ->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event): void {
                $member = $event->getData();
                $limit = $member instanceof CompanyMemberInterface ? $this->limitRepository->findOneByMember($member) : null;
                if (null === $limit) {
                    return;
                }
                $form = $event->getForm();
                $form->get('approvalOrderLimit')->setData(LimitAmount::format($limit->getOrderLimit()));
                $form->get('approvalApprovalLimit')->setData(LimitAmount::format($limit->getApprovalLimit()));
                $form->get('approvalCurrency')->setData($limit->getCurrencyCode());
            })
            ->addEventListener(FormEvents::POST_SUBMIT, $this->save(...), -10)
        ;
    }

    private function save(FormEvent $event): void
    {
        $form = $event->getForm();
        $member = $event->getData();
        if (!$member instanceof CompanyMemberInterface) {
            return;
        }
        $orderLimit = LimitAmount::parse(self::text($form->get('approvalOrderLimit')));
        $approvalLimit = LimitAmount::parse(self::text($form->get('approvalApprovalLimit')));
        $currency = self::text($form->get('approvalCurrency'));
        foreach (['approvalOrderLimit' => $orderLimit, 'approvalApprovalLimit' => $approvalLimit] as $field => $value) {
            if (false === $value) {
                $form->get($field)->addError(new FormError($this->translator->trans(MemberLimitUpdater::ERROR_INVALID_AMOUNT, [], 'validators')));
            }
        }
        if ((null !== $orderLimit || null !== $approvalLimit) && (null === $currency || '' === $currency)) {
            $form->get('approvalCurrency')->addError(new FormError($this->translator->trans(MemberLimitUpdater::ERROR_INVALID_CURRENCY, [], 'validators')));
        }
        if (!$form->isValid() || false === $orderLimit || false === $approvalLimit) {
            return;
        }

        $limit = $this->limitRepository->findOneByMember($member);
        if (null === $orderLimit && null === $approvalLimit) {
            if (null !== $limit) {
                $this->entityManager->remove($limit);
            }

            return;
        }
        if (null === $limit) {
            $limit = $this->limitFactory->createNew();
            $limit->setMember($member);
            $this->entityManager->persist($limit);
        }
        $limit->setOrderLimit($orderLimit);
        $limit->setApprovalLimit($approvalLimit);
        $limit->setCurrencyCode($currency);
        $limit->setUpdatedAt(new \DateTimeImmutable());
    }

    private static function text(FormInterface $field): ?string
    {
        $data = $field->getData();

        return is_scalar($data) ? (string) $data : null;
    }
}
