<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

use App\Module\Foundation\Application\ListRevisionStale;
use App\Module\Foundation\Application\ReadConsistentList;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS)]
final readonly class ListRevisionListener
{
    private const array ROUTES = [
        'api_v1_instruments_list', 'api_v1_products_list', 'api_v1_product_models_list',
        'api_v1_categorization_rules_list', 'api_v1_portfolio_operations_list', 'api_v1_portfolio_valuations_list',
    ];

    public function __construct(private ReadConsistentList $read, private JsonRequestEnvelope $envelope)
    {
    }

    public function __invoke(ControllerArgumentsEvent $event): void
    {
        $request = $event->getRequest();
        $route = $request->attributes->getString('_route');
        if (!$event->isMainRequest() || !in_array($route, self::ROUTES, true) || !$request->headers->has('X-List-Revision')) {
            return;
        }
        $expected = $request->headers->get('X-List-Revision') ?? '';
        $controller = $event->getController();
        $arguments = $event->getArguments();
        $query = $request->query->all();
        unset($query['page']);
        ksort($query);
        $scope = json_encode([$route, $request->attributes->get('_route_params'), $query], JSON_THROW_ON_ERROR);
        $invalid = ('start' !== $expected && 1 !== preg_match('/^[a-f0-9]{64}$/D', $expected))
            || ('start' === $expected && !in_array($request->query->get('page', '1'), ['1', 1], true));
        $event->setController(function () use ($invalid, $expected, $scope, $route, $controller, $arguments): Response {
            if ($invalid) {
                return $this->refusal(400, 'list_revision_invalid');
            }
            try {
                $result = ($this->read)($expected, $scope, 'api_v1_products_list' === $route, static function () use ($controller, $arguments): Response {
                    $response = $controller(...$arguments);
                    if (!$response instanceof Response) {
                        throw new \LogicException('A list controller must return a response.');
                    }

                    return $response;
                });
            } catch (ListRevisionStale) {
                return $this->refusal(409, 'list_revision_stale');
            } catch (WorkspaceAccessDenied) {
                return $this->refusal(403, 'list_revision_forbidden');
            }
            $response = $result['value'];
            if ($response->isSuccessful()) {
                $response->headers->set('X-List-Revision', $result['revision']);
            }

            return $response;
        });
        $event->setArguments([]);
    }

    private function refusal(int $status, string $key): Response
    {
        return $this->envelope->problem($status, 'api.problem.'.$key, '/problems/lists.'.match ($key) {
            'list_revision_stale' => 'revision_stale',
            'list_revision_forbidden' => 'forbidden',
            default => 'invalid_revision',
        });
    }
}
