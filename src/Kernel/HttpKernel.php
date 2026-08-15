<?php

declare (strict_types = 1);

namespace Phpcp\Kernel;

use Phpcp\Agent\AgentException;
use Phpcp\Http\ApiProblem;
use Phpcp\Http\ErrorPage;
use Phpcp\Middleware\AuditContext;
use Phpcp\Middleware\Authenticate;
use Phpcp\Middleware\Authorize;
use Phpcp\Middleware\CsrfProtection;
use Phpcp\Middleware\Middleware;
use Phpcp\Middleware\RateLimit;
use Phpcp\Middleware\SecurityHeaders;
use Phpcp\Middleware\SessionMiddleware;

/**
 * Wires everything together — ARCHITECTURE §3.2
 *
 * Middleware order matters and must not be reshuffled:
 *   SecurityHeaders  outermost — headers must ride on every response, error pages too
 *   RateLimit        drops bursts before they touch the database or cost an Argon2id hash
 *   Session          loads the user; must come before CSRF since the token is bound to it
 *   Authenticate     not signed in → redirect to login
 *   CsrfProtection   rejects requests with no token before any logic runs
 *   Authorize        checks the route's permission
 *   AuditContext     innermost — records what actually happened
 */
final class HttpKernel
{
    private readonly Router $router;

    /**
     * @param App $app
     */
    public function __construct(private readonly App $app)
    {
        $this->router = Routes::build();
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function handle(Request $request): Response
    {
        // This request's language comes from the cookie the SPA writes when the user
        // switches language — the one source that matches what they actually chose
        // (`Accept-Language` reports the browser's language, not the panel's)
        $this->app->setLocale($request->cookie('phpcp_lang'));

        $ctx = new Ctx($this->app);

        $match = $this->router->match($request->method, $request->path);

        if ($match !== null) {
            $ctx->route = $match['route'];
            $request = $request->withParams($match['params']);
        }

        $terminal = fn(Request $r): Response => $match === null
            ? $this->notFound($r, $ctx)
            : $this->invoke($match['route'], $r, $ctx);

        try {
            return $this->pipeline($terminal, $ctx)($request);
        } catch (\Throwable $e) {
            // An exception doesn't travel back through the middleware stack that was
            // still on it, so this response carries no security headers on its own —
            // wrap it in SecurityHeaders here or the error page ships without CSP,
            // behaving differently from every normal page for no good reason
            return (new SecurityHeaders())->handle(
                $request,
                $ctx,
                fn(Request $r): Response => $this->handleException($e, $r, $ctx),
            );
        }
    }

    /**
     * Wraps the middleware together from the inside out
     *
     * @param callable(Request):Response $terminal
     * @return callable(Request):Response
     */
    private function pipeline(callable $terminal, Ctx $ctx): callable
    {
        $stack = $terminal;

        foreach (array_reverse($this->middleware()) as $middleware) {
            $next = $stack;
            $stack = static fn(Request $r): Response => $middleware->handle($r, $ctx, $next);
        }

        return $stack;
    }

    /** @return list<Middleware> */
    private function middleware(): array
    {
        return [
            new SecurityHeaders(),
            new RateLimit(),
            new SessionMiddleware(),
            new Authenticate(),
            new CsrfProtection(),
            new Authorize(),
            new AuditContext()
        ];
    }

    /**
     * @param Route $route
     * @param Request $request
     * @param Ctx $ctx
     */
    private function invoke(Route $route, Request $request, Ctx $ctx): Response
    {
        // Unparseable JSON must be rejected before it reaches the controller — otherwise
        // whatever was sent silently becomes "not sent at all", and the caller gets a
        // 422 about a missing field when the real problem is one stray comma in the body
        if ($request->isApiV2() && $request->hasBrokenJson()) {
            return ApiProblem::BadRequest->response($this->app->t('The request body is not valid JSON'));
        }

        $controller = new $route->controller($this->app, $ctx, $this->router);

        if (!method_exists($controller, $route->action)) {
            throw new \LogicException("No such action {$route->controller}::{$route->action}");
        }

        return $controller->{$route->action}($request);
    }

    /**
     * @param Request $request
     * @param Ctx $ctx
     */
    private function notFound(Request $request, Ctx $ctx): Response
    {
        // The path exists under a different method → 405, which helps far more while
        // debugging than a flat 404
        $status = $this->router->pathExists($request->path) ? 405 : 404;

        if ($request->isApiV2()) {
            return $status === 405
                ? ApiProblem::MethodNotAllowed->response($this->app->t('This route does not support that method'))
                : ApiProblem::NotFound->response($this->app->t('The requested route was not found'));
        }

        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'error' => $this->app->t('The requested route was not found')], $status);
        }

        return ErrorPage::response(
            $status,
            $status === 405 ? $this->app->t('Method not allowed') : $this->app->t('Page not found'),
            $status === 405
                ? $this->app->t('This route exists, but does not support that method')
                : $this->app->t('The page you requested does not exist'),
        );
    }

    /**
     * @param \Throwable $e
     * @param Request $request
     * @param Ctx $ctx
     */
    private function handleException(\Throwable $e, Request $request, Ctx $ctx): Response
    {
        $isAgentError = $e instanceof AgentException;

        $this->app->logger()->error($isAgentError ? 'Command failed' : 'Unhandled error', [
            'request_id' => $request->requestId,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile().':'.$e->getLine(),
            'path' => $request->path,
            'user' => $ctx->username()
        ]);

        $status = $isAgentError ? 400 : 500;

        /*
         * This is the one boundary where an agent exception message becomes a
         * response the user reads. Capabilities throw messages written in English —
         * that string is the translation key, so `t()` returns Thai when a
         * translation exists in th.json and the English original otherwise. Messages
         * built with sprintf() (an id, a path) rarely match a catalogue key and stay
         * English on purpose — those are for whoever reads the log, not the customer.
         */
        $message = $isAgentError
            ? $this->app->t($e->getMessage())
            : $this->app->t(
                'An internal error occurred. Check the log (reference: {id})',
                ['id' => $request->requestId],
            );

        // REST API v2 needs a machine-readable code and a status that actually means
        // something.
        //
        // The old behaviour collapsed every kind of agent error into the same 400,
        // so a caller couldn't tell "the value you sent was wrong" (fix it and retry)
        // from "the agent isn't answering" (go check whether the service is down) —
        // two situations that call for completely different responses.
        if ($request->isApiV2()) {
            $problem = $isAgentError
                ? ApiProblem::fromAgentException($e)
                : ApiProblem::InternalError;

            return $problem->response($message);
        }

        if ($request->wantsJson()) {
            return Response::json(['ok' => false, 'error' => $message], $status);
        }

        // Don't show $message straight to a browser visitor for an internal error —
        // that text is for whoever reads the log; the reference id is what the user
        // needs to hand back
        return ErrorPage::response(
            $status,
            $isAgentError ? $this->app->t('The action failed') : $this->app->t('An internal error occurred'),
            $isAgentError ? $message : $this->app->t('Reference: {id}', ['id' => $request->requestId]),
        );
    }
}
