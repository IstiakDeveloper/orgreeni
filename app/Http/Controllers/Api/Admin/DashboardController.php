<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\ContactMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStats()
    {
        // Get today and this month dates
        $today = Carbon::today();
        $startOfMonth = Carbon::now()->startOfMonth();

        // Calculate overall stats
        $totalOrders = Order::count();
        $totalCustomers = User::where('role', 'customer')->count();
        $totalProducts = Product::count();
        $totalRevenue = Order::where('status', 'delivered')->sum('total');

        // Calculate today's stats
        $todayOrders = Order::whereDate('created_at', $today)->count();
        $todayRevenue = Order::whereDate('created_at', $today)
            ->where('status', 'delivered')
            ->sum('total');
        $todayCustomers = User::where('role', 'customer')
            ->whereDate('created_at', $today)
            ->count();

        // Calculate this month's stats
        $monthlyOrders = Order::where('created_at', '>=', $startOfMonth)->count();
        $monthlyRevenue = Order::where('created_at', '>=', $startOfMonth)
            ->where('status', 'delivered')
            ->sum('total');
        $monthlyCustomers = User::where('role', 'customer')
            ->where('created_at', '>=', $startOfMonth)
            ->count();

        // Get recent pending orders
        $pendingOrders = Order::whereIn('status', ['pending', 'confirmed'])
            ->with(['items.product', 'deliveryArea', 'deliverySlot'])
            ->latest()
            ->limit(5)
            ->get();

        // Get low-stock products
        $lowStockProducts = Product::whereRaw('(SELECT SUM(quantity) FROM product_stocks WHERE product_id = products.id) <= stock_alert_quantity')
            ->with('category')
            ->limit(5)
            ->get();

        // Get unread contact messages
        $unreadMessages = ContactMessage::where('is_read', false)
            ->latest()
            ->limit(5)
            ->get();

        // Get order status distribution
        $orderStatusDistribution = Order::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status')
            ->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'overall' => [
                    'total_orders' => $totalOrders,
                    'total_customers' => $totalCustomers,
                    'total_products' => $totalProducts,
                    'total_revenue' => $totalRevenue
                ],
                'today' => [
                    'orders' => $todayOrders,
                    'revenue' => $todayRevenue,
                    'new_customers' => $todayCustomers
                ],
                'monthly' => [
                    'orders' => $monthlyOrders,
                    'revenue' => $monthlyRevenue,
                    'new_customers' => $monthlyCustomers
                ],
                'pending_orders' => $pendingOrders,
                'low_stock_products' => $lowStockProducts,
                'unread_messages' => $unreadMessages,
                'order_status_distribution' => $orderStatusDistribution
            ]
        ]);
    }

    /**
     * Get sales chart data
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSalesChart(Request $request)
    {
        $period = $request->input('period', 'weekly');

        switch ($period) {
            case 'daily':
                $chartData = $this->getDailySalesData();
                break;
            case 'monthly':
                $chartData = $this->getMonthlySalesData();
                break;
            case 'yearly':
                $chartData = $this->getYearlySalesData();
                break;
            case 'weekly':
            default:
                $chartData = $this->getWeeklySalesData();
                break;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'chart_data' => $chartData
            ]
        ]);
    }

    /**
     * Get daily sales data (last 24 hours)
     *
     * @return array
     */
    private function getDailySalesData()
    {
        $data = [];

        // Get sales for each hour of the last 24 hours
        for ($i = 0; $i < 24; $i++) {
            $hour = Carbon::now()->subHours($i);
            $startHour = Carbon::now()->subHours($i)->startOfHour();
            $endHour = Carbon::now()->subHours($i)->endOfHour();

            $sales = Order::whereBetween('created_at', [$startHour, $endHour])
                ->where('status', '!=', 'cancelled')
                ->sum('total');

            $ordersCount = Order::whereBetween('created_at', [$startHour, $endHour])
                ->where('status', '!=', 'cancelled')
                ->count();

            $data[] = [
                'time' => $hour->format('h A'),
                'sales' => $sales,
                'orders' => $ordersCount
            ];
        }

        // Reverse to get chronological order
        return array_reverse($data);
    }

    /**
     * Get weekly sales data (last 7 days)
     *
     * @return array
     */
    private function getWeeklySalesData()
    {
        $data = [];

        // Get sales for each day of the last 7 days
        for ($i = 0; $i < 7; $i++) {
            $day = Carbon::now()->subDays($i);

            $sales = Order::whereDate('created_at', $day->toDateString())
                ->where('status', '!=', 'cancelled')
                ->sum('total');

            $ordersCount = Order::whereDate('created_at', $day->toDateString())
                ->where('status', '!=', 'cancelled')
                ->count();

            $data[] = [
                'day' => $day->format('D'),
                'date' => $day->format('M d'),
                'sales' => $sales,
                'orders' => $ordersCount
            ];
        }

        // Reverse to get chronological order
        return array_reverse($data);
    }

    /**
     * Get monthly sales data (last 12 months)
     *
     * @return array
     */
    private function getMonthlySalesData()
    {
        $data = [];

        // Get sales for each month of the last 12 months
        for ($i = 0; $i < 12; $i++) {
            $month = Carbon::now()->subMonths($i);
            $startMonth = Carbon::now()->subMonths($i)->startOfMonth();
            $endMonth = Carbon::now()->subMonths($i)->endOfMonth();

            $sales = Order::whereBetween('created_at', [$startMonth, $endMonth])
                ->where('status', '!=', 'cancelled')
                ->sum('total');

            $ordersCount = Order::whereBetween('created_at', [$startMonth, $endMonth])
                ->where('status', '!=', 'cancelled')
                ->count();

            $data[] = [
                'month' => $month->format('M'),
                'year' => $month->format('Y'),
                'sales' => $sales,
                'orders' => $ordersCount
            ];
        }

        // Reverse to get chronological order
        return array_reverse($data);
    }

    /**
     * Get yearly sales data (last 5 years)
     *
     * @return array
     */
    private function getYearlySalesData()
    {
        $data = [];

        // Get sales for each year of the last 5 years
        for ($i = 0; $i < 5; $i++) {
            $year = Carbon::now()->subYears($i);
            $startYear = Carbon::now()->subYears($i)->startOfYear();
            $endYear = Carbon::now()->subYears($i)->endOfYear();

            $sales = Order::whereBetween('created_at', [$startYear, $endYear])
                ->where('status', '!=', 'cancelled')
                ->sum('total');

            $ordersCount = Order::whereBetween('created_at', [$startYear, $endYear])
                ->where('status', '!=', 'cancelled')
                ->count();

            $data[] = [
                'year' => $year->format('Y'),
                'sales' => $sales,
                'orders' => $ordersCount
            ];
        }

        // Reverse to get chronological order
        return array_reverse($data);
    }
}
