import { useEffect, useState } from 'react';
import { AlertCircle, BarChart3, Loader2, TrendingUp } from 'lucide-react';
import { adminApiRequest } from '../utils/adminApi';

interface SalesSummary {
  successful_order_count: number;
  total_sales: number;
  average_order_value: number;
  highest_order_value: number;
  lowest_order_value: number;
  paying_customers: number;
}

interface StatusBreakdownItem {
  status: string;
  total_orders: number;
  total_amount: number;
}

interface DailySalesPoint {
  date: string;
  order_count: number;
  total_sales: number;
}

interface MonthlySalesPoint {
  month: string;
  order_count: number;
  total_sales: number;
}

interface TopBookRevenueItem {
  book_id: number;
  title: string;
  author: string;
  total_quantity_sold: number;
  total_revenue: number;
}

interface SalesReport {
  summary: SalesSummary;
  status_breakdown: StatusBreakdownItem[];
  daily_sales: DailySalesPoint[];
  monthly_sales: MonthlySalesPoint[];
  top_books: TopBookRevenueItem[];
}

interface OrdersSummary {
  total_orders: number;
  pending_orders: number;
  paid_orders: number;
  shipped_orders: number;
  completed_orders: number;
  cancelled_orders: number;
  cancellation_rate: number;
  completion_rate: number;
  average_items_per_order: number;
}

interface TopCustomerItem {
  account_id: number;
  fname: string;
  lname: string;
  email: string;
  total_orders: number;
  total_amount: number;
}

interface TopBookQuantityItem {
  book_id: number;
  title: string;
  author: string;
  total_quantity_ordered: number;
}

interface RecentOrderItem {
  order_id: number;
  account_id: number;
  customer_name: string;
  status: string;
  total_amount: number;
  created_at: string;
}

interface OrdersReport {
  summary: OrdersSummary;
  status_breakdown: StatusBreakdownItem[];
  top_customers: TopCustomerItem[];
  top_books_by_quantity: TopBookQuantityItem[];
  recent_orders: RecentOrderItem[];
}

function formatCurrency(value: number): string {
  return value.toLocaleString('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

function formatCompactCurrency(value: number): string {
  return new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    notation: 'compact',
    maximumFractionDigits: 1,
  }).format(value);
}

function formatShortDate(value: string): string {
  return new Date(value).toLocaleDateString('en-PH', {
    month: 'short',
    day: 'numeric',
  });
}

function formatMonthLabel(value: string): string {
  const [year, month] = value.split('-');
  return new Date(Number(year), Number(month) - 1, 1).toLocaleDateString('en-PH', {
    month: 'short',
    year: 'numeric',
  });
}

function formatDateTime(value: string): string {
  return new Date(value).toLocaleString('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

function buildLineChartPath(values: number[], width: number, height: number): string {
  if (values.length === 0) {
    return '';
  }

  const maxValue = Math.max(...values, 1);
  const stepX = values.length === 1 ? 0 : width / (values.length - 1);

  return values
    .map((value, index) => {
      const x = index * stepX;
      const y = height - (value / maxValue) * height;
      return `${index === 0 ? 'M' : 'L'} ${x} ${y}`;
    })
    .join(' ');
}

function getLineChartPointPosition(index: number, totalPoints: number, width: number): number {
  if (totalPoints <= 1) {
    return width / 2;
  }

  return (width / (totalPoints - 1)) * index;
}

function StatCard({ label, value, caption }: { label: string; value: string; caption?: string }) {
  return (
    <article className="admin-report-stat-card">
      <span>{label}</span>
      <strong>{value}</strong>
      {caption ? <p>{caption}</p> : null}
    </article>
  );
}

function EmptyChart({ message }: { message: string }) {
  return <div className="admin-report-empty">{message}</div>;
}

function LineSalesChart({ data }: { data: DailySalesPoint[] }) {
  const chartWidth = 420;
  const chartHeight = 200;
  const chartPaddingX = 26;
  const axisY = 212;
  const values = data.map((point) => point.total_sales);
  const path = buildLineChartPath(values, chartWidth, chartHeight);

  if (data.length === 0) {
    return <EmptyChart message="No daily sales data yet." />;
  }

  return (
    <div className="admin-line-chart">
      <svg viewBox={`0 0 ${chartWidth + chartPaddingX * 2} 244`} role="img" aria-label="Daily sales trend">
        <g transform={`translate(${chartPaddingX}, 12)`}>
          <path d={`M 0 ${axisY - 12} L ${chartWidth} ${axisY - 12}`} className="admin-line-chart__axis" />
          <path d={path} className="admin-line-chart__path" />
        {data.map((point, index) => {
          const maxValue = Math.max(...values, 1);
          const x = getLineChartPointPosition(index, data.length, chartWidth);
          const y = chartHeight - (point.total_sales / maxValue) * chartHeight;

          return (
            <g key={point.date}>
              <circle cx={x} cy={y} r="5" className="admin-line-chart__dot" />
              <text x={x} y={axisY + 14} textAnchor="middle" className="admin-line-chart__label">
                {formatShortDate(point.date)}
              </text>
            </g>
          );
        })}
        </g>
      </svg>
    </div>
  );
}

function MonthlySalesBars({ data }: { data: MonthlySalesPoint[] }) {
  const maxValue = Math.max(...data.map((point) => point.total_sales), 1);

  if (data.length === 0) {
    return <EmptyChart message="No monthly sales data yet." />;
  }

  return (
    <div className="admin-vertical-bars">
      {data.map((point) => (
        <div key={point.month} className="admin-vertical-bars__item">
          <span className="admin-vertical-bars__value">{formatCompactCurrency(point.total_sales)}</span>
          <div className="admin-vertical-bars__track">
            <div
              className="admin-vertical-bars__fill"
              style={{ height: `${Math.max((point.total_sales / maxValue) * 100, 8)}%` }}
            />
          </div>
          <span className="admin-vertical-bars__label">{formatMonthLabel(point.month)}</span>
        </div>
      ))}
    </div>
  );
}

function HorizontalMetricList<TItem>({
  items,
  getKey,
  getLabel,
  getSubLabel,
  getValue,
  formatValue,
}: {
  items: TItem[];
  getKey: (item: TItem) => string | number;
  getLabel: (item: TItem) => string;
  getSubLabel?: (item: TItem) => string;
  getValue: (item: TItem) => number;
  formatValue: (item: TItem) => string;
}) {
  const maxValue = Math.max(...items.map((item) => getValue(item)), 1);

  if (items.length === 0) {
    return <EmptyChart message="No data available yet." />;
  }

  return (
    <div className="admin-horizontal-metrics">
      {items.map((item) => (
        <article key={getKey(item)} className="admin-horizontal-metrics__item">
          <div className="admin-horizontal-metrics__copy">
            <strong>{getLabel(item)}</strong>
            {getSubLabel ? <span>{getSubLabel(item)}</span> : null}
          </div>
          <div className="admin-horizontal-metrics__bar">
            <div
              className="admin-horizontal-metrics__fill"
              style={{ width: `${Math.max((getValue(item) / maxValue) * 100, 6)}%` }}
            />
          </div>
          <span className="admin-horizontal-metrics__value">{formatValue(item)}</span>
        </article>
      ))}
    </div>
  );
}

export default function AdminReports() {
  const [salesReport, setSalesReport] = useState<SalesReport | null>(null);
  const [ordersReport, setOrdersReport] = useState<OrdersReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    const loadReports = async () => {
      try {
        setLoading(true);
        setError('');

        const [salesData, ordersData] = await Promise.all([
          adminApiRequest<{ success: boolean; report: SalesReport }>('/api/reports/sales'),
          adminApiRequest<{ success: boolean; report: OrdersReport }>('/api/reports/orders'),
        ]);

        setSalesReport(salesData.report);
        setOrdersReport(ordersData.report);
      } catch (loadError) {
        setError(loadError instanceof Error ? loadError.message : 'Failed to load report data');
      } finally {
        setLoading(false);
      }
    };

    void loadReports();
  }, []);

  const salesSummary = salesReport?.summary;
  const ordersSummary = ordersReport?.summary;
  const salesStatusBreakdown = salesReport?.status_breakdown ?? [];
  const orderStatusBreakdown = ordersReport?.status_breakdown ?? [];
  const dailySales = salesReport?.daily_sales ?? [];
  const monthlySales = salesReport?.monthly_sales ?? [];
  const topBooks = salesReport?.top_books ?? [];
  const topCustomers = ordersReport?.top_customers ?? [];
  const topBooksByQuantity = ordersReport?.top_books_by_quantity ?? [];
  const recentOrders = ordersReport?.recent_orders ?? [];

  if (loading) {
    return (
      <section className="admin-orders-state">
        <Loader2 className="spin" size={28} />
        <p>Loading report analytics...</p>
      </section>
    );
  }

  if (error || !salesSummary || !ordersSummary) {
    return (
      <section className="admin-orders-state admin-orders-state--error">
        <AlertCircle size={26} />
        <p>{error || 'Unable to load reports right now.'}</p>
      </section>
    );
  }

  return (
    <section className="admin-report-page">
      <section className="admin-report-section">
        <div className="admin-report-section__header">
          <div>
            <p className="admin-report-section__eyebrow">Sales</p>
            <h3>Revenue overview</h3>
          </div>
          <TrendingUp size={20} />
        </div>

        <div className="admin-report-stat-grid">
          <StatCard label="Total Sales" value={formatCurrency(salesSummary.total_sales)} caption={`${salesSummary.successful_order_count} successful orders`} />
          <StatCard label="Average Order" value={formatCurrency(salesSummary.average_order_value)} caption={`${salesSummary.paying_customers} paying customers`} />
          <StatCard label="Highest Order" value={formatCurrency(salesSummary.highest_order_value)} />
          <StatCard label="Lowest Order" value={formatCurrency(salesSummary.lowest_order_value)} />
        </div>

        <div className="admin-report-chart-grid admin-report-chart-grid--sales">
          <article className="admin-report-card admin-report-card--wide">
            <div className="admin-report-card__header">
              <h4>Daily sales trend</h4>
              <span>Last 7 active days</span>
            </div>
            <LineSalesChart data={dailySales} />
          </article>

          <article className="admin-report-card">
            <div className="admin-report-card__header">
              <h4>Monthly sales</h4>
              <span>Last 6 months</span>
            </div>
            <MonthlySalesBars data={monthlySales} />
          </article>
        </div>

        <div className="admin-report-chart-grid admin-report-chart-grid--secondary">
          <article className="admin-report-card">
            <div className="admin-report-card__header">
              <h4>Sales by order status</h4>
              <span>Amount and volume</span>
            </div>
            <HorizontalMetricList
              items={salesStatusBreakdown}
              getKey={(item) => item.status}
              getLabel={(item) => item.status}
              getValue={(item) => item.total_amount}
              formatValue={(item) => `${formatCompactCurrency(item.total_amount)} • ${item.total_orders} orders`}
            />
          </article>

          <article className="admin-report-card">
            <div className="admin-report-card__header">
              <h4>Top books by revenue</h4>
              <span>Paid, shipped, completed</span>
            </div>
            <HorizontalMetricList
              items={topBooks}
              getKey={(item) => item.book_id}
              getLabel={(item) => item.title}
              getSubLabel={(item) => item.author}
              getValue={(item) => item.total_revenue}
              formatValue={(item) => `${formatCompactCurrency(item.total_revenue)} • ${item.total_quantity_sold} sold`}
            />
          </article>
        </div>
      </section>

      <section className="admin-report-section">
        <div className="admin-report-section__header">
          <div>
            <p className="admin-report-section__eyebrow">Orders</p>
            <h3>Order statistics</h3>
          </div>
          <BarChart3 size={20} />
        </div>

        <div className="admin-report-stat-grid">
          <StatCard label="Total Orders" value={`${ordersSummary.total_orders}`} caption={`${ordersSummary.average_items_per_order} average items/order`} />
          <StatCard label="Completion Rate" value={`${ordersSummary.completion_rate}%`} caption={`${ordersSummary.completed_orders} completed`} />
          <StatCard label="Cancellation Rate" value={`${ordersSummary.cancellation_rate}%`} caption={`${ordersSummary.cancelled_orders} cancelled`} />
          <StatCard label="Pending To Ship" value={`${ordersSummary.pending_orders + ordersSummary.paid_orders}`} caption="Pending plus paid orders" />
        </div>

        <div className="admin-report-chart-grid admin-report-chart-grid--secondary">
          <article className="admin-report-card">
            <div className="admin-report-card__header">
              <h4>Order status breakdown</h4>
              <span>Count and amount</span>
            </div>
            <HorizontalMetricList
              items={orderStatusBreakdown}
              getKey={(item) => item.status}
              getLabel={(item) => item.status}
              getValue={(item) => item.total_orders}
              formatValue={(item) => `${item.total_orders} orders • ${formatCompactCurrency(item.total_amount)}`}
            />
          </article>

          <article className="admin-report-card">
            <div className="admin-report-card__header">
              <h4>Top customers</h4>
              <span>By order volume</span>
            </div>
            <HorizontalMetricList
              items={topCustomers}
              getKey={(item) => item.account_id}
              getLabel={(item) => `${item.fname} ${item.lname}`}
              getSubLabel={(item) => item.email}
              getValue={(item) => item.total_orders}
              formatValue={(item) => `${item.total_orders} orders • ${formatCompactCurrency(item.total_amount)}`}
            />
          </article>
        </div>

        <div className="admin-report-chart-grid admin-report-chart-grid--secondary">
          <article className="admin-report-card">
            <div className="admin-report-card__header">
              <h4>Top books by quantity</h4>
              <span>Ordered volume</span>
            </div>
            <HorizontalMetricList
              items={topBooksByQuantity}
              getKey={(item) => item.book_id}
              getLabel={(item) => item.title}
              getSubLabel={(item) => item.author}
              getValue={(item) => item.total_quantity_ordered}
              formatValue={(item) => `${item.total_quantity_ordered} ordered`}
            />
          </article>

          <article className="admin-report-card">
            <div className="admin-report-card__header">
              <h4>Recent orders</h4>
              <span>Latest customer activity</span>
            </div>
            <div className="admin-report-recent-list">
              {recentOrders.length === 0 ? (
                <EmptyChart message="No recent orders yet." />
              ) : (
                recentOrders.map((order) => (
                  <article key={order.order_id} className="admin-report-recent-item">
                    <div>
                      <strong>Order #{order.order_id}</strong>
                      <p>{order.customer_name}</p>
                    </div>
                    <div className="admin-report-recent-item__meta">
                      <span className={`admin-order-status admin-order-status--${order.status}`}>{order.status}</span>
                      <strong>{formatCurrency(order.total_amount)}</strong>
                      <p>{formatDateTime(order.created_at)}</p>
                    </div>
                  </article>
                ))
              )}
            </div>
          </article>
        </div>
      </section>
    </section>
  );
}
