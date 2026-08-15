<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeaveControllerSplitTest extends TestCase
{
    #[DataProvider('leaveRoutes')]
    public function test_legacy_leave_endpoints_are_wired_to_responsibility_specific_controllers(
        string $method,
        string $uri,
        string $action,
    ): void {
        $route = collect(RouteFacade::getRoutes()->getRoutes())
            ->first(fn (Route $route) => $route->uri() === $uri && in_array($method, $route->methods(), true));

        $this->assertNotNull($route, "Missing route {$method} {$uri}");
        $this->assertSame($action, $route->getActionName());
    }

    public static function leaveRoutes(): array
    {
        return [
            ['GET', 'api/leaves', 'App\\Http\\Controllers\\LeaveQueryController@index'],
            ['GET', 'api/leaves/team-calendar', 'App\\Http\\Controllers\\LeaveQueryController@teamCalendar'],
            ['GET', 'api/leaves/balances', 'App\\Http\\Controllers\\LeaveQueryController@balances'],
            ['GET', 'api/leaves/types', 'App\\Http\\Controllers\\LeaveQueryController@types'],
            ['POST', 'api/leaves', 'App\\Http\\Controllers\\LeaveRequestController@store'],
            ['PUT', 'api/leaves/{leave}', 'App\\Http\\Controllers\\LeaveRequestController@update'],
            ['DELETE', 'api/leaves/{leave}', 'App\\Http\\Controllers\\LeaveRequestController@destroy'],
            ['POST', 'api/admin/leaves', 'App\\Http\\Controllers\\LeaveRequestController@storeAsAdmin'],
            ['POST', 'api/leaves/{leave}/cancel', 'App\\Http\\Controllers\\LeaveCancellationController@cancel'],
            ['POST', 'api/leaves/{leave}/approve-cancellation', 'App\\Http\\Controllers\\LeaveCancellationController@approve'],
            ['POST', 'api/leaves/{leave}/reject-cancellation', 'App\\Http\\Controllers\\LeaveCancellationController@reject'],
            ['POST', 'api/leaves/{leave}/hr-verify', 'App\\Http\\Controllers\\LeaveWorkflowActionController@hrVerify'],
            ['POST', 'api/leaves/{leave}/substitute-accept', 'App\\Http\\Controllers\\LeaveWorkflowActionController@acceptSubstitute'],
            ['POST', 'api/leaves/{leave}/substitute-reject', 'App\\Http\\Controllers\\LeaveWorkflowActionController@rejectSubstitute'],
            ['GET', 'api/leaves/{leave}/pdf', 'App\\Http\\Controllers\\LeaveDocumentController@exportPdf'],
            ['GET', 'api/leaves/{leave}/word', 'App\\Http\\Controllers\\LeaveDocumentController@exportWord'],
        ];
    }
}
