<?php

declare(strict_types=1);

namespace Tmonier\SyliusPurchaseApprovalPlugin\Controller\Shop;

use Doctrine\ORM\Tools\Pagination\Paginator;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Entity\CompanyMemberInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Member\CurrentMemberResolverInterface;
use Tmonier\SyliusCompanyAccountsPlugin\Repository\CompanyMemberRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\ApprovalDecisionMaker;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\Decider;
use Tmonier\SyliusPurchaseApprovalPlugin\Approval\MemberLimitUpdater;
use Tmonier\SyliusPurchaseApprovalPlugin\Entity\OrderApprovalInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\MemberLimitRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Repository\OrderApprovalRepositoryInterface;
use Tmonier\SyliusPurchaseApprovalPlugin\Security\ApprovalPermissions;

/**
 * Customer account pages (under /account, Sylius access control): approvals of the company
 * (approvers), one approval (approvers and the buyer), purchase limits (company admins).
 * No membership or an approval out of reach: 404; permission refused: 403.
 */
final class ApprovalController extends AbstractController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly CurrentMemberResolverInterface $currentMemberResolver,
        private readonly OrderApprovalRepositoryInterface $approvalRepository,
        private readonly CompanyMemberRepositoryInterface $memberRepository,
        private readonly MemberLimitRepositoryInterface $limitRepository,
        private readonly ApprovalDecisionMaker $decisionMaker,
        private readonly MemberLimitUpdater $limitUpdater,
        private readonly ChannelContextInterface $channelContext,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function indexAction(Request $request): Response
    {
        [$member, $company] = $this->membership(ApprovalPermissions::APPROVAL_LIST);
        $pendingOnly = !$request->query->getBoolean('all');
        $page = max(1, $request->query->getInt('page', 1));

        $paginator = new Paginator(
            $this->approvalRepository->createListQueryBuilderByCompany($company, $pendingOnly)
                ->setFirstResult(($page - 1) * self::PER_PAGE)
                ->setMaxResults(self::PER_PAGE),
        );
        $total = count($paginator);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $pages) {
            return $this->redirectToRoute('tmonier_purchase_approval_shop_approvals', ['page' => $pages] + ($pendingOnly ? [] : ['all' => 1]));
        }

        return $this->render('@TmonierSyliusPurchaseApprovalPlugin/shop/account/approvals/index.html.twig', [
            'company' => $company,
            'member' => $member,
            'approvals' => iterator_to_array($paginator),
            'pending_only' => $pendingOnly,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    public function showAction(string $number): Response
    {
        $approval = $this->visibleApproval($number);

        return $this->render('@TmonierSyliusPurchaseApprovalPlugin/shop/account/approvals/show.html.twig', [
            'approval' => $approval,
            'order' => $approval->getOrder(),
            'company' => $approval->getCompany(),
            'member' => $this->currentMemberResolver->getMember(),
            'can_decide' => $this->isGranted(ApprovalPermissions::APPROVAL_DECIDE, $approval),
        ]);
    }

    public function decideAction(Request $request, string $number, string $decision): Response
    {
        $approval = $this->visibleApproval($number);
        if (!$this->isGranted(ApprovalPermissions::APPROVAL_DECIDE, $approval)) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isCsrfTokenValid('tmonier_approval_' . $number, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        $member = $this->currentMemberResolver->getMember();
        if (null === $member) {
            throw $this->createAccessDeniedException();
        }
        $comment = (string) $request->request->get('comment', '');
        if (mb_strlen($comment) > ApprovalDecisionMaker::COMMENT_MAX_LENGTH) {
            $this->addFlash('error', $this->translator->trans('tmonier_purchase_approval.approval.comment_too_long', ['%max%' => ApprovalDecisionMaker::COMMENT_MAX_LENGTH], 'validators'));

            return $this->redirectToRoute('tmonier_purchase_approval_shop_approval_show', ['number' => $number]);
        }

        $error = 'approve' === $decision
            ? $this->decisionMaker->approve($approval, Decider::member($member), $comment)
            : $this->decisionMaker->reject($approval, Decider::member($member), $comment);
        if (null !== $error) {
            $this->addFlash('error', $this->translator->trans($error, [], 'validators'));
        } else {
            $this->addFlash('success', 'approve' === $decision ? 'tmonier_purchase_approval.order_approved' : 'tmonier_purchase_approval.order_rejected');
        }

        return $this->redirectToRoute('tmonier_purchase_approval_shop_approval_show', ['number' => $number]);
    }

    public function limitsAction(): Response
    {
        [$member, $company] = $this->membership(ApprovalPermissions::LIMITS_MANAGE);

        return $this->render('@TmonierSyliusPurchaseApprovalPlugin/shop/account/limits/index.html.twig', [
            'company' => $company,
            'member' => $member,
            'members' => $this->memberRepository->findByCompany($company),
            'limits' => $this->limitRepository->findByCompanyIndexedByMemberId($company),
            'currencies' => $this->currencies(),
        ]);
    }

    public function updateLimitAction(Request $request, int $id): Response
    {
        [$member, $company] = $this->membership(ApprovalPermissions::LIMITS_MANAGE);
        $target = $this->memberRepository->findOneByIdAndCompany($id, $company);
        if (null === $target) {
            throw $this->createNotFoundException('Member not found.');
        }
        if (!$this->isCsrfTokenValid('tmonier_member_limit_' . $id, (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $error = $this->limitUpdater->update(
            $member,
            $target,
            $this->field($request, 'order_limit'),
            $this->field($request, 'approval_limit'),
            $this->field($request, 'currency'),
            $this->currencies(),
        );
        if (null !== $error) {
            $this->addFlash('error', $this->translator->trans($error, [], 'validators'));
        } else {
            $this->addFlash('success', 'tmonier_purchase_approval.limits_updated');
        }

        return $this->redirectToRoute('tmonier_purchase_approval_shop_limits');
    }

    /** @return array{CompanyMemberInterface, CompanyInterface} */
    private function membership(string $attribute): array
    {
        $member = $this->currentMemberResolver->getMember();
        $company = $member?->getCompany();
        if (null === $member || null === $company) {
            throw $this->createNotFoundException('No company.');
        }
        if (!$this->isGranted($attribute, $company)) {
            throw $this->createAccessDeniedException();
        }

        return [$member, $company];
    }

    /** The approval of an order the customer may see (buyer or approver of its company), else 404. */
    private function visibleApproval(string $number): OrderApprovalInterface
    {
        $approval = $this->approvalRepository->findOneByOrderNumber($number);
        if (null === $approval || !$this->isGranted(ApprovalPermissions::APPROVAL_VIEW, $approval)) {
            throw $this->createNotFoundException('Approval not found.');
        }

        return $approval;
    }

    /** @return list<string> */
    private function currencies(): array
    {
        $channel = $this->channelContext->getChannel();
        $codes = [];
        if ($channel instanceof ChannelInterface) {
            $codes[] = $channel->getBaseCurrency()?->getCode();
            foreach ($channel->getCurrencies() as $currency) {
                $codes[] = $currency->getCode();
            }
        }

        return array_values(array_unique(array_filter($codes, static fn (?string $code): bool => null !== $code && '' !== $code)));
    }

    private function field(Request $request, string $name): ?string
    {
        $value = $request->request->get($name);

        return is_scalar($value) ? (string) $value : null;
    }
}
