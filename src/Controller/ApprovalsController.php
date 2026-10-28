<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Controller;

use Acencyril\ToolGuardBundle\Approval\ApprovalStore;
use Acencyril\ToolGuardBundle\Approval\Approver;
use Acencyril\ToolGuardBundle\CurrentUser;
use Acencyril\ToolGuardBundle\Journal\Journal;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * The approvers' page (routes in config/routes.php, import them under your admin): what waits,
 * with its arguments in full, Approve / Reject, and the latest journal. Only for approver_role;
 * every action is a POST with a CSRF token. Without security: 404, never open.
 */
final class ApprovalsController
{
    public function __construct(
        private readonly ApprovalStore $store,
        private readonly Approver $approver,
        private readonly Journal $journal,
        private readonly CurrentUser $user,
        private readonly ?Environment $twig,
        private readonly ?AuthorizationCheckerInterface $auth,
        private readonly ?CsrfTokenManagerInterface $csrf,
        private readonly UrlGeneratorInterface $urls,
        private readonly string $role,
    ) {
    }

    public function index(): Response
    {
        $this->check();

        return $this->private(new Response((string) $this->twig?->render('@ToolGuard/approvals.html.twig', [
            'pending' => $this->store->all('pending'),
            'decided' => array_values(array_filter($this->store->all(null, 30), static fn ($a) => 'pending' !== $a->status)),
            'journal' => $this->journal->latest(30),
        ])));
    }

    public function decide(Request $request, string $id, string $decision): Response
    {
        $this->check();
        if (null === $this->csrf || !$this->csrf->isTokenValid(new CsrfToken('tool_guard', (string) $request->request->get('_token')))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
        try {
            'approve' === $decision ? $this->approver->approve($id, $this->user->identifier()) : $this->approver->reject($id, $this->user->identifier());
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        } catch (\LogicException) {
            // already decided (two approvers, a double click): the page shows the outcome
        }

        return $this->private(new RedirectResponse($this->urls->generate('tool_guard_approvals').'#r-'.$id, 303));
    }

    private function check(): void
    {
        if (null === $this->twig || null === $this->auth) {
            throw new NotFoundHttpException();
        }
        try {
            $ok = $this->auth->isGranted($this->role);
        } catch (\Throwable) {
            $ok = false;
        }
        if (!$ok) {
            throw new AccessDeniedHttpException();
        }
    }

    private function private(Response $r): Response
    {
        $r->headers->set('Cache-Control', 'no-store, private');
        $r->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $r;
    }
}
