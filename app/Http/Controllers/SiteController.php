<?php

namespace App\Http\Controllers;

use App\DTOs\Route\RouteResolution;
use App\Enums\HttpMethod;
use App\Enums\RouteResolutionOutcome;
use App\Http\Resources\ContentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Website;
use App\Services\AssetService;
use App\Services\ContentService;
use App\Services\RouteResolverService;
use CN\PHTML\ArrayTAG;
use CN\PHTML\Core\BODY;
use CN\PHTML\Core\H1;
use CN\PHTML\Core\H2;
use CN\PHTML\Core\HEAD;
use CN\PHTML\Core\HTML;
use CN\PHTML\Core\MAIN;
use CN\PHTML\Core\META;
use CN\PHTML\Core\P;
use CN\PHTML\Core\SPAN;
use CN\PHTML\Core\STYLE;
use CN\PHTML\Core\TITLE;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The public front door for every tenant website: resolves whatever path a
 * visitor's browser (or a frontend consuming this API) requested, against
 * the routes table of whichever Website the request's Host header matches.
 * A host that doesn't belong to any managed website falls back to this
 * platform's own placeholder page.
 *
 * Registered as a single catch-all in routes/web.php via a controller
 * reference (not a closure), so route:cache keeps working in production.
 */
class SiteController extends Controller
{
    public function __construct(
        private readonly RouteResolverService $resolver,
        private readonly ContentService $content,
        private readonly AssetService $assets,
    ) {}

    public function show(Request $request, ?string $path = null): Response|RedirectResponse|JsonResponse
    {
        $website = Website::query()->active()->forHost($request->getHost())->first();

        if ($website === null) {
            return $this->platformPlaceholder();
        }

        $resolution = $this->resolver->resolve($website, '/'.($path ?? ''), HttpMethod::Get);

        return match ($resolution->outcome) {
            RouteResolutionOutcome::Page => $this->servePage($website, $resolution),
            RouteResolutionOutcome::Asset => $this->serveAsset($website, $resolution),
            RouteResolutionOutcome::Redirect => redirect()->to($resolution->finalRoute->path, $resolution->httpStatus),
            RouteResolutionOutcome::ExternalRedirect => redirect()->away($resolution->finalRoute->redirect_url, $resolution->httpStatus),
            default => ApiResponse::error($this->errorMessage($resolution->outcome), status: $resolution->httpStatus),
        };
    }

    /**
     * The routes table can still point at a page that's since been
     * unpublished — ContentService only ever serves the last published
     * version, so that's reported the same as any other unresolved path.
     */
    private function servePage(Website $website, RouteResolution $resolution): JsonResponse
    {
        try {
            $content = $this->content->findPublished($website, $resolution->finalRoute->page->slug);
        } catch (ModelNotFoundException) {
            return ApiResponse::error('Resource not found.', status: 404);
        }

        return ApiResponse::success(new ContentResource($content));
    }

    private function serveAsset(Website $website, RouteResolution $resolution): RedirectResponse
    {
        $download = $this->assets->downloadUrl($website, $resolution->finalRoute->asset);

        return redirect()->away($download['url']);
    }

    private function errorMessage(RouteResolutionOutcome $outcome): string
    {
        return match ($outcome) {
            RouteResolutionOutcome::LoopDetected => 'This redirect loops back on itself.',
            RouteResolutionOutcome::TooManyRedirects => 'This redirect chains too many hops deep.',
            default => 'Resource not found.',
        };
    }

    private function platformPlaceholder(): Response
    {
        $html = new HTML;
        $html->setLang('pt-BR');

        $head = new HEAD;
        $head->append(new META(charset: 'UTF-8'));
        $head->append(new META('viewport', content: 'width=device-width, initial-scale=1.0'));
        $head->append(new TITLE('Cautnew CMS'));
        $head->append(new STYLE(null, <<<'CSS'
            :root {
                color-scheme: dark;
                font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            }

            *, *::before, *::after { box-sizing: border-box; }

            html, body {
                margin: 0;
                padding: 0;
                height: 100%;
                background: linear-gradient(135deg, #050816 0%, #101a35 100%);
                color: #f8fafc;
            }

            body {
                min-height: 100vh;
                min-height: 100dvh;
                overflow: hidden;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: clamp(1rem, 3vw, 2rem);
            }

            .hero-card {
                width: min(100%, 760px);
                max-height: 100%;
                display: flex;
                flex-direction: column;
                justify-content: center;
                gap: clamp(0.75rem, 2vw, 1rem);
                overflow: hidden;
            }

            .eyebrow {
                display: inline-block;
                width: fit-content;
                margin: 0 0 0.25rem;
                padding: 0.4rem 0.75rem;
                border-radius: 999px;
                background: rgba(34, 211, 238, 0.16);
                color: #67e8f9;
                font-size: clamp(0.7rem, 2vw, 0.8rem);
                font-weight: 700;
                letter-spacing: 0.2em;
                text-transform: uppercase;
            }

            h1 {
                margin: 0;
                font-size: clamp(1.75rem, 6vw, 3rem);
                line-height: 1.1;
                max-width: 100%;
            }

            h2 {
                margin: 0;
                font-size: clamp(1rem, 3.4vw, 1.35rem);
                line-height: 1.35;
                color: #bfdbfe;
                max-width: 100%;
                font-weight: 500;
            }

            p {
                margin: 0;
                font-size: clamp(0.9rem, 2.6vw, 1.05rem);
                line-height: 1.6;
                color: #dbeafe;
                max-width: 100%;
            }

            .highlight {
                color: #7dd3fc;
                font-weight: 700;
            }
        CSS));

        $body = new BODY;
        $heroCard = new MAIN('hero-card');
        $heroCard->append(new ArrayTAG([
            (new SPAN('eyebrow'))->append('Em breve'),
            (new H1)->append('Este CMS está sendo preparado'),
            (new H2)->append('Vai trazer muita facilidade para o seu fluxo de trabalho'),
            (new P)->append('Ele combina simplicidade com muita autonomia, para que você crie, organize e publique conteúdos com mais liberdade e menos esforço.'),
            (new P)->append('Em breve, este espaço será um aliado prático para quem quer trabalhar com mais agilidade, clareza e controle.'),
        ]));

        $body->append($heroCard);

        $html->append($head);
        $html->append($body);

        return response($html->render(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
