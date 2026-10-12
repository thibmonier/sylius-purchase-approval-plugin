<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Api\Shop;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Component\Locale\Context\LocaleNotFoundException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Member\CurrentMemberResolverInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Api\ApprovalDecisionInput;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalDecisionMaker;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalPolicy;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\Decider;

/** @implements ProcessorInterface<ApprovalDecisionInput, ShopApprovalView> */
final class ShopApprovalDecisionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ShopApprovalProvider $provider,
        private readonly CurrentMemberResolverInterface $currentMemberResolver,
        private readonly ApprovalDecisionMaker $decisionMaker,
        private readonly TranslatorInterface $translator,
        private readonly LocaleContextInterface $localeContext,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ShopApprovalView
    {
        $approval = $this->provider->find($uriVariables);
        $member = $this->currentMemberResolver->getMember();
        if (null === $member) {
            throw new AccessDeniedHttpException('Access denied.');
        }
        $comment = $data->comment;
        if (null !== $comment && mb_strlen($comment) > ApprovalDecisionMaker::COMMENT_MAX_LENGTH) {
            throw new UnprocessableEntityHttpException($this->trans('tmonier_purchase_approval.approval.comment_too_long', ['%max%' => ApprovalDecisionMaker::COMMENT_MAX_LENGTH]));
        }

        $approve = str_ends_with((string) $operation->getName(), '_approve');
        $error = $approve
            ? $this->decisionMaker->approve($approval, Decider::member($member), $comment)
            : $this->decisionMaker->reject($approval, Decider::member($member), $comment);
        if (ApprovalPolicy::ERROR_NOT_ALLOWED === $error) {
            throw new AccessDeniedHttpException($this->trans($error));
        }
        if (null !== $error) {
            throw new UnprocessableEntityHttpException($this->trans($error));
        }

        return $this->provider->view($approval);
    }

    /** @param array<string, mixed> $parameters */
    private function trans(string $key, array $parameters = []): string
    {
        try {
            $locale = $this->localeContext->getLocaleCode();
        } catch (LocaleNotFoundException) {
            $locale = null;
        }

        return $this->translator->trans($key, $parameters, 'validators', $locale);
    }
}
