<?php

declare(strict_types=1);

namespace Acencyril\ToolGuardBundle\Controller;

use Acencyril\ToolGuardBundle\Approval\ApprovalStore;
use Acencyril\ToolGuardBundle\Approval\Approver;
use Acencyril\ToolGuardBundle\CurrentUser;
use Acencyril\ToolGuardBundle\Invocation;
use Acencyril\ToolGuardBundle\Journal\Journal;
use Acencyril\ToolGuardBundle\Stop\Stop;
use Acencyril\ToolGuardBundle\Stop\StopButton;
use Acencyril\ToolGuardBundle\ToolCatalog;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * The approvers' page (routes in config/routes.php, import them under your admin): the stop button
 * (every tool, one tool, one agent) and what is stopped, what waits, with its arguments in full,
 * Approve / Reject, and the latest journal. Only for approver_role; every action is a POST with a
 * CSRF token. Without security: 404, never open.
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
        private readonly string $mode = 'enforce',
        private readonly ?int $expireAfter = null,
        private readonly ?StopButton $button = null,
        /** @var list<class-string> */
        private readonly array $toolClasses = [],
        /** @var list<string> */
        private readonly array $agents = [],
    ) {
    }

    public function index(): Response
    {
        $this->check();
        $pending = $this->store->all('pending');
        try {
            $stops = $this->button?->active() ?? [];
            $unreadable = false;
        } catch (\RuntimeException) {
            [$stops, $unreadable] = [[], true];
        }
        $blocked = [];
        foreach ($pending as $a) {
            $stop = Stop::matching($stops, $a->tool, $a->agent, Invocation::HUMAN !== $a->channel);
            if (null !== $stop || $unreadable) {
                $blocked[$a->id] = $stop?->describe() ?? 'the stop state cannot be read';
            }
        }
        $stoppedTools = [];
        foreach ($stops as $s) {
            $stoppedTools[$s->key()] = $s;
        }

        return $this->private(new Response((string) $this->twig?->render('@ToolGuard/approvals.html.twig', [
            'stop_button' => null !== $this->button,
            'stops' => $stops,
            'stops_unreadable' => $unreadable,
            'stopped' => $stoppedTools,
            'blocked' => $blocked,
            'tools' => ToolCatalog::names($this->toolClasses),
            'agents' => self::sorted($this->agents),
            'pending' => $pending,
            'decided' => array_values(array_filter($this->store->all(null, 30), static fn ($a) => 'pending' !== $a->status)),
            'journal' => $this->journal->latest(30),
            'mode' => $this->mode,
            'expire_after' => $this->expireAfter,
        ])));
    }

    public function decide(Request $request, string $id, string $decision): Response
    {
        $this->check();
        $this->csrfCheck($request);
        try {
            'approve' === $decision ? $this->approver->approve($id, $this->user->identifier()) : $this->approver->reject($id, $this->user->identifier());
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException();
        } catch (\LogicException $e) {
            // already decided or being decided (two approvers, a double click), expired, stopped, or
            // the approver's own request: nothing ran, the page says why
            if ($request->hasSession()) {
                $request->getSession()->getFlashBag()->add('tool_guard', $e->getMessage());
            }
        }

        return $this->private(new RedirectResponse($this->urls->generate('tool_guard_approvals').'#r-'.$id, 303));
    }

    /** The stop button: every agent (recommended), one agent, one tool, or everything (emergency). Nothing matching runs until resume. */
    public function stop(Request $request): Response
    {
        $this->check();
        $this->csrfCheck($request);
        [$scope, $target] = $this->scope($request);
        $this->button?->stop(new Stop($scope, $target, $this->user->identifier(), new \DateTimeImmutable(), (string) $request->request->get('reason', '')));

        return $this->private(new RedirectResponse($this->urls->generate('tool_guard_approvals'), 303));
    }

    public function resume(Request $request): Response
    {
        $this->check();
        $this->csrfCheck($request);
        [$scope, $target] = $this->scope($request);
        $this->button?->resume($scope, $target, $this->user->identifier());

        return $this->private(new RedirectResponse($this->urls->generate('tool_guard_approvals'), 303));
    }

    /** @return array{string, ?string} */
    private function scope(Request $request): array
    {
        if (null === $this->button) {
            throw new NotFoundHttpException();
        }
        $scope = (string) $request->request->get('scope', '');
        $target = trim((string) $request->request->get('target', ''));
        if (!\in_array($scope, [Stop::ALL, Stop::AGENTS, Stop::TOOL, Stop::AGENT], true) || (Stop::named($scope) && ('' === $target || mb_strlen($target) > 200))) {
            throw new BadRequestHttpException('Stop: scope "agents" or "all", or "tool" / "agent" with its name.');
        }

        return [$scope, Stop::named($scope) ? $target : null];
    }

    /** @param list<string> $names */
    private static function sorted(array $names): array
    {
        sort($names);

        return $names;
    }

    private function csrfCheck(Request $request): void
    {
        if (null === $this->csrf || !$this->csrf->isTokenValid(new CsrfToken('tool_guard', (string) $request->request->get('_token')))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
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
