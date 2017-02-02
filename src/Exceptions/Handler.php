<?php

namespace A17\CmsToolkit\Exceptions;

use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Inspector;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Handler extends ExceptionHandler
{
    protected $dontReport = [
        AuthorizationException::class,
        HttpException::class,
        ModelNotFoundException::class,
        ValidationException::class,
    ];

    public function report(Exception $e)
    {
        return parent::report($e);
    }

    public function render($request, Exception $e)
    {
        $e = $this->prepareException($e);

        /*
         * See Laravel 5.4 Changelog https://laravel.com/docs/5.4/upgrade
         * The Illuminate\Http\Exception\HttpResponseException has been renamed to Illuminate\Http\Exceptions\HttpResponseException.
         * Note that Exceptions is now plural.
         */
        $laravel53HttpResponseException = 'Illuminate\Http\Exception\HttpResponseException';
        $laravel54HttpResponseException = 'Illuminate\Http\Exceptions\HttpResponseException';

        $httpResponseExceptionClass = class_exists($laravel54HttpResponseException) ? $laravel54HttpResponseException : $laravel53HttpResponseException;

        if ($e instanceof $httpResponseExceptionClass || $e instanceof ValidationException) {
            return $e->getResponse();
        } elseif ($e instanceof AuthenticationException) {
            return $this->handleUnauthenticated($request, $e);
        }

        if (config('app.debug', false) && config('cms-toolkit.debug.use_inspector', false)) {
            return Inspector::renderException($e);
        }

        if (config('app.debug', false) && config('cms-toolkit.debug.use_whoops', false)) {
            return $this->renderExceptionWithWhoops($request, $e);
        }

        if ($this->isHttpException($e)) {
            return $this->renderHttpExceptionWithView($request, $e);
        } elseif (app()->environment('production', 'preprod')) {
            return response()->view('front.errors.500', [], 500);
        }

        return parent::render($request, $e);
    }

    public function renderHttpExceptionWithView($request, $e)
    {
        $statusCode = $e->getStatusCode();

        if (config('app.debug')) {
            return $this->convertExceptionToResponse($e);
        }

        if ($request->getHost() == config('cms-toolkit.admin_url')) {
            $view = "admin.errors.{$statusCode}";
        } else {
            $view = "front.errors.{$statusCode}";
        }

        if (view()->exists($view)) {
            return response()->view($view, ['exception' => $e], $statusCode, $e->getHeaders());
        }

        return $this->renderHttpException($e);
    }

    protected function renderExceptionWithWhoops($request, Exception $e)
    {
        $this->unsetSensitiveData();

        $whoops = new \Whoops\Run();

        if ($request->ajax() || $request->wantsJson()) {
            $handler = new \Whoops\Handler\JsonResponseHandler();
        } else {
            $handler = new \Whoops\Handler\PrettyPageHandler();

            if (app()->environment('local')) {
                $handler->setEditor(function ($file, $line) {
                    $translations = array('^' . config('cms-toolkit.debug.whoops_path_guest') => config('cms-toolkit.debug.whoops_path_host'));
                    foreach ($translations as $from => $to) {
                        $file = rawurlencode(preg_replace('#' . $from . '#', $to, $file, 1));
                    }
                    return array(
                        'url' => "subl://open?url=$file&line=$line",
                        'ajax' => false,
                    );
                });
            }
        }

        $handler->addResourcePath(base_path('public/assets/admin/vendor'));
        $handler->addCustomCss('whoops.base.css');

        $whoops->pushHandler($handler);

        return response(
            $whoops->handleException($e),
            method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500,
            method_exists($e, 'getHeaders') ? $e->getHeaders() : []
        );
    }

    /**
     * Don't ever display sensitive data in Whoops pages.
     */
    protected function unsetSensitiveData()
    {
        foreach ($_ENV as $key => $value) {
            unset($_SERVER[$key]);
        }

        $_ENV = [];
    }

    protected function handleUnauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        return redirect()->guest(route('admin.login'));
    }
}
