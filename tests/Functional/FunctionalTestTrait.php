<?php

declare(strict_types=1);

namespace Tests\Tmonier\SyliusPurchaseApprovalPlugin\Functional;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\SessionTokenStorage;
use Tests\Tmonier\SyliusPurchaseApprovalPlugin\PurchaseApprovalTestTrait;

trait FunctionalTestTrait
{
    use PurchaseApprovalTestTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->beginTestTransaction();
    }

    protected function tearDown(): void
    {
        $this->rollBackTestTransaction();
        parent::tearDown();
    }

    private function loginAdmin(): AdminUserInterface
    {
        $admin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => 'sylius']);
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        $this->client->loginUser($admin, 'admin');

        return $admin;
    }

    private function loginShopUser(CustomerInterface $customer): void
    {
        $user = $customer->getUser();
        self::assertInstanceOf(ShopUserInterface::class, $user);
        $this->client->loginUser($user, 'shop');
    }

    private function logout(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->restart();
        $this->client->disableReboot();
    }

    private function shopUrl(string $path): string
    {
        return sprintf('/%s%s', $this->catalog()->localeCode(), $path);
    }

    /** @return array<string, string> */
    private function shopApiHeaders(?CustomerInterface $as = null, string $contentType = 'application/ld+json'): array
    {
        $headers = ['CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/ld+json'];
        if (null !== $as) {
            $user = $as->getUser();
            self::assertInstanceOf(ShopUserInterface::class, $user);
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->jwtManager()->create($user);
        }

        return $headers;
    }

    /** @return array<string, string> */
    private function adminApiHeaders(string $contentType = 'application/ld+json'): array
    {
        $admin = $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => 'api@example.com'])
            ?? $this->entityManager()->getRepository(AdminUserInterface::class)->findOneBy(['username' => 'sylius']);
        self::assertInstanceOf(AdminUserInterface::class, $admin);
        // Admin API endpoints require ROLE_API_ACCESS (granted to the fixture administrators).
        $admin->addRole('ROLE_API_ACCESS');
        $this->entityManager()->flush();

        return ['CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/ld+json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwtManager()->create($admin)];
    }

    /** @return array<mixed> */
    private function json(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data, (string) $this->client->getResponse()->getContent());

        return $data;
    }

    /** @return list<string> */
    private function flashes(Crawler $crawler): array
    {
        /** @var list<string> $flashes */
        $flashes = $crawler->filter('[data-test-flash-messages]')->each(static fn (Crawler $node): string => trim($node->text()));

        return $flashes;
    }

    /**
     * A valid CSRF token of the browser session (the signed-in user's), for requests that no
     * rendered form offers to that user.
     */
    private function sessionCsrfToken(string $id): string
    {
        $this->client->request('GET', $this->shopUrl('/'));
        $request = $this->client->getRequest();
        $session = $request->getSession();
        // No RequestStack constructor argument before Symfony 7.2.
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $manager = new CsrfTokenManager(null, new SessionTokenStorage($requestStack));
        $value = $manager->getToken($id)->getValue();
        $session->save();
        $this->client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));

        return $value;
    }

    private function choice(Form $form, string $name): ChoiceFormField
    {
        $field = $form[$name];
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }

    private function jwtManager(): JWTTokenManagerInterface
    {
        $manager = self::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        self::assertInstanceOf(JWTTokenManagerInterface::class, $manager);

        return $manager;
    }
}
