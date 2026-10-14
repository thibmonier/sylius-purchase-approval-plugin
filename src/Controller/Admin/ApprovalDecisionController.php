<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Controller\Admin;

use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalDecisionMaker;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\Decider;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;

/**
 * Store administrators approve or reject from the order page (e.g. no approver available).
 * They are not bound by the company rules (limits, own orders); the decision is recorded with
 * their name.
 */
final class ApprovalDecisionController extends AbstractController
{
    /** @param OrderRepositoryInterface<OrderInterface> $orderRepository */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderApprovalRepositoryInterface $approvalRepository,
        private readonly ApprovalDecisionMaker $decisionMaker,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, int $id, string $decision): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $order = $this->orderRepository->find($id);
        $approval = $order instanceof OrderInterface ? $this->approvalRepository->findOneByOrder($order) : null;
        if (!$approval instanceof \Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface) {
            throw $this->createNotFoundException('Approval not found.');
        }
        if (!$this->isCsrfTokenValid('tmonier_admin_approval_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $comment = (string) $request->request->get('comment', '');
        $user = $this->getUser();
        $decider = Decider::administrator($user instanceof AdminUserInterface ? $user : null);

        $error = 'approve' === $decision
            ? $this->decisionMaker->approve($approval, $decider, $comment)
            : $this->decisionMaker->reject($approval, $decider, $comment);
        if (null !== $error) {
            $this->addFlash('error', $this->translator->trans($error, [], 'validators'));
        } else {
            $this->addFlash('success', 'approve' === $decision ? 'tmonier_purchase_approval.order_approved' : 'tmonier_purchase_approval.order_rejected');
        }

        return $this->redirectToRoute('sylius_admin_order_show', ['id' => $id]);
    }
}
