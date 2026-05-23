import { useEffect, useMemo, useState } from 'react';
import { AlertCircle, BookOpenText, Loader2 } from 'lucide-react';
import { adminApiRequest } from '../utils/adminApi';

interface OrderUser {
  account_id: number;
  fname: string;
  lname: string;
  email: string;
  phone?: string;
}

interface OrderItem {
  order_item_id: number;
  book_id: number;
  title: string;
  author: string;
  quantity: number;
  price_at_purchase: number;
  book_cover_image: string | null;
}

interface AdminOrder {
  order_id: number;
  account_id: number;
  total_amount: number;
  payment_method?: string;
  status: string;
  created_at: string;
  updated_at: string;
  user: OrderUser;
  delivery?: {
    address?: string;
  };
  items: OrderItem[];
}

type OrderSortField = 'newest' | 'oldest';

function formatPrice(value: number): string {
  return value.toLocaleString('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

function formatOrderTimestamp(value: string): string {
  return new Date(value).toLocaleString('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

export default function AdminOrders() {
  const [orders, setOrders] = useState<AdminOrder[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [updatingOrderId, setUpdatingOrderId] = useState<number | null>(null);
  const [sortField, setSortField] = useState<OrderSortField>('newest');

  useEffect(() => {
    const loadOrders = async () => {
      try {
        setLoading(true);
        setError('');

        const data = await adminApiRequest<{ success: boolean; orders: AdminOrder[] }>('/api/admin/orders');
        setOrders(data.orders);
      } catch (loadError) {
        setError(loadError instanceof Error ? loadError.message : 'Failed to load orders');
      } finally {
        setLoading(false);
      }
    };

    void loadOrders();
  }, []);

  const handleUpdateOrderStatus = async (orderId: number, nextStatus: 'paid' | 'shipped') => {
    try {
      setUpdatingOrderId(orderId);
      setError('');
      setSuccess('');

      const data = await adminApiRequest<{ success: boolean; message: string; order: AdminOrder }>(
        `/api/admin/orders/${orderId}/status`,
        {
          method: 'PUT',
          body: JSON.stringify({ status: nextStatus }),
        },
      );

      setOrders((current) =>
        current.map((order) => (order.order_id === orderId ? data.order : order)),
      );
      setSuccess(data.message);
    } catch (updateError) {
      setError(updateError instanceof Error ? updateError.message : 'Failed to update order status');
    } finally {
      setUpdatingOrderId(null);
    }
  };

  const orderCounts = useMemo(
    () => ({
      pending: orders.filter((order) => order.status === 'pending').length,
      paid: orders.filter((order) => order.status === 'paid').length,
      shipped: orders.filter((order) => order.status === 'shipped').length,
      completed: orders.filter((order) => order.status === 'completed').length,
    }),
    [orders],
  );

  const sortedOrders = useMemo(() => {
    const sorted = [...orders];

    sorted.sort((left, right) => {
      const comparison = new Date(left.created_at).getTime() - new Date(right.created_at).getTime();

      if (comparison === 0) {
        return left.order_id - right.order_id;
      }

      return sortField === 'newest' ? -comparison : comparison;
    });

    return sorted;
  }, [orders, sortField]);

  if (loading) {
    return (
      <section className="admin-orders-state">
        <Loader2 className="spin" size={28} />
        <p>Loading all user orders...</p>
      </section>
    );
  }

  if (error) {
    return (
      <section className="admin-orders-state admin-orders-state--error">
        <AlertCircle size={26} />
        <p>{error}</p>
      </section>
    );
  }

  return (
    <section className="admin-orders-page">
      {(error || success) ? (
        <div className="admin-orders-messages">
          {error ? <p className="admin-orders-message admin-orders-message--error">{error}</p> : null}
          {success ? <p className="admin-orders-message admin-orders-message--success">{success}</p> : null}
        </div>
      ) : null}

      <div className="admin-orders-hero">
        <div>
          <p className="admin-orders-eyebrow">View Orders</p>
          <h2>All user orders</h2>
          <p>Every placed order is listed here so the admin can monitor customer purchases in one place.</p>
        </div>

        <div className="admin-orders-summary">
          <article className="admin-orders-summary-card">
            <div>
              <strong>{orderCounts.pending}</strong>
              <span>Pending Orders</span>
            </div>
          </article>

          <article className="admin-orders-summary-card">
            <div>
              <strong>{orderCounts.paid}</strong>
              <span>Paid Orders</span>
            </div>
          </article>

          <article className="admin-orders-summary-card">
            <div>
              <strong>{orderCounts.shipped}</strong>
              <span>Shipped Orders</span>
            </div>
          </article>

          <article className="admin-orders-summary-card">
            <div>
              <strong>{orderCounts.completed}</strong>
              <span>Completed Orders</span>
            </div>
          </article>
        </div>
      </div>

      {orders.length === 0 ? (
        <div className="admin-orders-empty">
          <p>No user orders found yet.</p>
        </div>
      ) : (
        <div className="admin-orders-list">
          <div className="admin-orders-toolbar">
            <label className="admin-orders-toolbar__group">
              <span>Sort by</span>
              <select value={sortField} onChange={(event) => setSortField(event.target.value as OrderSortField)}>
                <option value="newest">Newer</option>
                <option value="oldest">Older</option>
              </select>
            </label>
          </div>

          {sortedOrders.map((order) => (
            <article key={order.order_id} className="admin-order-card">
              <div className="admin-order-card__header">
                <div className="admin-order-card__meta">
                  <div className="admin-order-card__meta-top">
                    <h3>Order #{order.order_id}</h3>
                    <p>{order.user.fname} {order.user.lname}</p>
                  </div>

                  <div className="admin-order-card__meta-grid">
                    <div className="admin-order-card__meta-item">
                      <span className="admin-order-card__meta-label">Phone</span>
                      <strong>{order.user.phone || 'No saved contact number'}</strong>
                    </div>

                    <div className="admin-order-card__meta-item">
                      <span className="admin-order-card__meta-label">Email</span>
                      <strong>{order.user.email}</strong>
                    </div>

                    <div className="admin-order-card__meta-item admin-order-card__meta-item--full">
                      <span className="admin-order-card__meta-label">Delivery Address</span>
                      <strong className="admin-order-card__address">
                        {order.delivery?.address || 'No saved address'}
                      </strong>
                    </div>
                  </div>
                </div>

                <div className="admin-order-card__details">
                  <span className={`admin-order-status admin-order-status--${order.status}`}>{order.status}</span>
                  <p><strong>Bought:</strong> {formatOrderTimestamp(order.created_at)}</p>
                  {order.payment_method ? <p><strong>Payment:</strong> {order.payment_method}</p> : null}
                  <p><strong>Total:</strong> {formatPrice(order.total_amount)}</p>
                  {order.status === 'pending' ? (
                    <button
                      type="button"
                      className="admin-order-action-btn"
                      onClick={() => void handleUpdateOrderStatus(order.order_id, 'paid')}
                      disabled={updatingOrderId === order.order_id}
                    >
                      {updatingOrderId === order.order_id ? 'Updating...' : 'Mark as paid'}
                    </button>
                  ) : null}
                  {order.status === 'paid' ? (
                    <button
                      type="button"
                      className="admin-order-action-btn admin-order-action-btn--ship"
                      onClick={() => void handleUpdateOrderStatus(order.order_id, 'shipped')}
                      disabled={updatingOrderId === order.order_id}
                    >
                      {updatingOrderId === order.order_id ? 'Updating...' : 'Mark as shipped'}
                    </button>
                  ) : null}
                </div>
              </div>

              <div className="admin-order-items">
                <div className="admin-order-items__head">
                  <span>Product</span>
                  <span>Price</span>
                  <span>Quantity</span>
                  <span>Subtotal</span>
                </div>

                {order.items.map((item) => (
                  <div key={item.order_item_id} className="admin-order-item-row">
                    <div className="admin-order-item-book">
                      {item.book_cover_image ? (
                        <img
                          src={`/backend/uploads/books/${item.book_cover_image}`}
                          alt={item.title}
                          className="admin-order-item-book__cover"
                        />
                      ) : (
                        <div className="admin-order-item-book__cover admin-order-item-book__cover--empty">
                          <BookOpenText size={18} />
                        </div>
                      )}

                      <div>
                        <strong>{item.title}</strong>
                        <span>{item.author}</span>
                      </div>
                    </div>
                    <span>{formatPrice(item.price_at_purchase)}</span>
                    <span>{item.quantity}</span>
                    <span>{formatPrice(item.price_at_purchase * item.quantity)}</span>
                  </div>
                ))}
              </div>
            </article>
          ))}
        </div>
      )}
    </section>
  );
}
