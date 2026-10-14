<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Api\Admin;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Api\ApprovalDecisionInput;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalDecisionMaker;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\Decider;

/** @implements ProcessorInterface<ApprovalDecisionInput, AdminApprovalView> */
final readonly class AdminApprovalDecisionProcessor implements ProcessorInterface
{
    public function __construct(
        private AdminApprovalProvider $provider,
        private ApprovalDecisionMaker $decisionMaker,
        private TokenStorageInterface $tokenStorage,
        private TranslatorInterface $translator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminApprovalView
    {
        $approval = $this->provider->find($uriVariables);
        $comment = $data->comment;
        if (null !== $comment && mb_strlen($comment) > ApprovalDecisionMaker::COMMENT_MAX_LENGTH) {
            throw new UnprocessableEntityHttpException($this->translator->trans('tmonier_purchase_approval.approval.comment_too_long', ['%max%' => ApprovalDecisionMaker::COMMENT_MAX_LENGTH], 'validators'));
        }
        $user = $this->tokenStorage->getToken()?->getUser();
        $decider = Decider::administrator($user instanceof AdminUserInterface ? $user : null);

        $error = str_ends_with((string) $operation->getName(), '_approve')
            ? $this->decisionMaker->approve($approval, $decider, $comment)
            : $this->decisionMaker->reject($approval, $decider, $comment);
        if (null !== $error) {
            throw new UnprocessableEntityHttpException($this->translator->trans($error, [], 'validators'));
        }

        return AdminApprovalView::fromApproval($approval);
    }
}
