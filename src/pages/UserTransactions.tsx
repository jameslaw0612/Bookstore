import { useEffect, useMemo, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { CheckCircle2, Loader2, ShoppingBag } from 'lucide-react'
import UserCartDrawer from '../components/UserCartDrawer'
import UserTopBar from '../components/UserTopBar'
import '../styles/Home.css'
import '../styles/UserPages.css'
import { apiRequest, getStoredUser, setStoredUser, type SessionUser } from '../utils/session'

interface OrderItem {
  order_item_id: number
  book_id: number
  title: string
  author: string
  quantity: number
  price_at_purchase: number
  book_cover_image?: string | null
}

interface Order {
  order_id: number
  account_id: number
  total_amount: number
  payment_method?: string
  status: string
  created_at: string
  updated_at: string
  items: OrderItem[]
}

interface CartItem {
  book_id: number
  title: string
  author: string
  price: number
  stock_quantity: number
  quantity: number
  book_cover_image: string | null
}

interface CartOrderItem {
  order_item_id: number
  book_id: number
  title: string
  author: string
  quantity: number
  price_at_purchase: number
  stock_quantity: number
  book_cover_image: string | null
}

interface CartOrder {
  order_id: number
  account_id: number
  total_amount: number
  payment_method?: string
  status: string
  created_at: string
  updated_at: string
  items: CartOrderItem[]
}

interface ProfileResponse {
  success: boolean
  profile: SessionUser
}

interface CheckoutRequest {
  paymentMethod: string
  addressId: number
  selectedBookIds: number[]
}

interface TransactionRow {
  key: string
  orderId: number
  orderItemId: number
  title: string
  author: string
  quantity: number
  price: number
  subtotal: number
  status: string
  boughtAt: string
  bookCoverImage: string | null
}

function mapCartOrderToItems(order: CartOrder | null): CartItem[] {
  if (!order) {
    return []
  }

  return order.items.map((item) => ({
    book_id: item.book_id,
    title: item.title,
    author: item.author,
    price: item.price_at_purchase,
    stock_quantity: item.stock_quantity,
    quantity: item.quantity,
    book_cover_image: item.book_cover_image,
  }))
}

function formatPrice(value: number): string {
  return value.toLocaleString('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

function formatOrderTimestamp(value: string): string {
  return new Date(value).toLocaleString('en-PH', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}

export default function UserTransactions() {
  const location = useLocation()
  const [isCartOpen, setIsCartOpen] = useState(false)
  const [orders, setOrders] = useState<Order[]>([])
  const [cartItems, setCartItems] = useState<CartItem[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [cartStatus, setCartStatus] = useState('')
  const [cartError, setCartError] = useState('')
  const [isCheckingOut, setIsCheckingOut] = useState(false)
  const [currentUser, setCurrentUser] = useState<SessionUser | null>(() => getStoredUser())
  const [cancellingOrderId, setCancellingOrderId] = useState<number | null>(null)
  const [cancelNotice, setCancelNotice] = useState<{ title: string; message: string } | null>(null)

  const storedUser = getStoredUser()

  const loadTransactions = async () => {
    if (!storedUser?.account_id) {
      setError('No user session found. Please log in again.')
      setLoading(false)
      return
    }

    try {
      setLoading(true)
      setError('')

      const [ordersData, cartData, profileData] = await Promise.all([
        apiRequest<{ success: boolean; orders: Order[] }>(`/api/orders/user/${storedUser.account_id}`),
        apiRequest<{ success: boolean; cart: CartOrder | null }>('/api/orders/cart'),
        apiRequest<ProfileResponse>('/api/users/profile'),
      ])

      setOrders(ordersData.orders)
      setCartItems(mapCartOrderToItems(cartData.cart))
      setCurrentUser(profileData.profile)
      setStoredUser(profileData.profile)
    } catch (loadError) {
      setError(loadError instanceof Error ? loadError.message : 'Failed to load transaction data')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadTransactions()
  }, [])

  useEffect(() => {
    const nextMessage = (location.state as { checkoutMessage?: string } | null)?.checkoutMessage
    if (nextMessage) {
      setSuccess(nextMessage)
    }
  }, [location.state])

  const transactionRows = useMemo<TransactionRow[]>(
    () =>
      orders.flatMap((order) =>
        order.status === 'cancelled'
          ? []
          : order.items.map((item) => ({
          key: `${order.order_id}-${item.order_item_id}`,
          orderId: order.order_id,
          orderItemId: item.order_item_id,
          title: item.title,
          author: item.author,
          quantity: item.quantity,
          price: item.price_at_purchase,
          subtotal: item.price_at_purchase * item.quantity,
          status: order.status,
          boughtAt: order.created_at,
          bookCoverImage: item.book_cover_image ?? null,
        })),
      ),
    [orders],
  )

  const cartCount = useMemo(
    () => cartItems.reduce((sum, item) => sum + item.quantity, 0),
    [cartItems],
  )

  const cartSubtotal = useMemo(
    () => cartItems.reduce((sum, item) => sum + item.price * item.quantity, 0),
    [cartItems],
  )

  const syncCartItems = async (nextItems: CartItem[], successMessage = '') => {
    const response = await apiRequest<{ success: boolean; message: string; cart: CartOrder | null }>('/api/orders/cart', {
      method: 'PUT',
      body: JSON.stringify({
        items: nextItems.map((item) => ({
          book_id: item.book_id,
          quantity: item.quantity,
        })),
      }),
    })

    setCartItems(mapCartOrderToItems(response.cart))
    setCartStatus(successMessage || '')
  }

  const handleIncrementItem = async (bookId: number) => {
    setCartStatus('')
    setCartError('')

    const nextItems = cartItems.map((item) =>
      item.book_id === bookId
        ? { ...item, quantity: Math.min(item.quantity + 1, item.stock_quantity) }
        : item,
    )

    try {
      await syncCartItems(nextItems)
    } catch (cartUpdateError) {
      setCartError(cartUpdateError instanceof Error ? cartUpdateError.message : 'Failed to update cart')
    }
  }

  const handleDecrementItem = async (bookId: number) => {
    setCartStatus('')
    setCartError('')

    const nextItems = cartItems.map((item) =>
      item.book_id === bookId
        ? { ...item, quantity: Math.max(item.quantity - 1, 1) }
        : item,
    )

    try {
      await syncCartItems(nextItems)
    } catch (cartUpdateError) {
      setCartError(cartUpdateError instanceof Error ? cartUpdateError.message : 'Failed to update cart')
    }
  }

  const handleRemoveItem = async (bookId: number) => {
    setCartStatus('')
    setCartError('')
    const nextItems = cartItems.filter((item) => item.book_id !== bookId)

    try {
      await syncCartItems(nextItems)
    } catch (cartUpdateError) {
      setCartError(cartUpdateError instanceof Error ? cartUpdateError.message : 'Failed to update cart')
    }
  }

  const handleCheckout = async ({ paymentMethod, addressId, selectedBookIds }: CheckoutRequest) => {
    if (cartItems.length === 0) {
      return
    }

    try {
      setIsCheckingOut(true)
      setCartError('')
      setCartStatus('')

      const response = await apiRequest<{ success: boolean; message: string; order: CartOrder; cart: CartOrder | null }>('/api/orders/checkout', {
        method: 'POST',
        body: JSON.stringify({
          address_id: addressId,
          selected_book_ids: selectedBookIds,
          payment_method: paymentMethod,
          status: 'pending',
        }),
      })

      setCartItems(mapCartOrderToItems(response.cart))
      setIsCartOpen(false)
      setSuccess(response.message)
      await loadTransactions()
    } catch (checkoutError) {
      const message = checkoutError instanceof Error ? checkoutError.message : 'Unable to place order'
      setCartError(message)
      throw checkoutError
    } finally {
      setIsCheckingOut(false)
    }
  }

  const handleCancelOrder = async (orderId: number, orderItemId: number, currentStatus: string) => {
    try {
      setCancellingOrderId(orderItemId)
      setError('')
      setSuccess('')

      await apiRequest<{ success: boolean; message: string }>(`/api/orders/${orderId}/items/${orderItemId}/cancel`, {
        method: 'POST',
      })

      setCancelNotice({
        title: currentStatus === 'paid' ? 'Item Refunded' : 'Item Cancelled',
        message: currentStatus === 'paid'
          ? 'This paid item has been cancelled and refunded.'
          : 'This pending item has been cancelled.',
      })
      await loadTransactions()
    } catch (cancelError) {
      setError(cancelError instanceof Error ? cancelError.message : 'Failed to cancel order')
    } finally {
      setCancellingOrderId(null)
    }
  }

  return (
    <div className="home-container">
      <UserTopBar
        activeNav="transactions"
        cartOpen={isCartOpen}
        cartCount={cartCount}
        transactionCount={orders.length}
        onCartClick={() => setIsCartOpen((prev) => !prev)}
      />

      <main className="home-main">
        <section className="user-page-shell">
          {loading ? (
            <div className="user-page-placeholder">
              <Loader2 className="user-page-spinner" size={32} />
              <p>Loading your transaction data...</p>
            </div>
          ) : (
            <section className="user-panel-card">
              {(error || success) && (
                <div className="user-page-messages">
                  {error && <p className="user-page-message error">{error}</p>}
                  {success && <p className="user-page-message success"><CheckCircle2 size={16} /> {success}</p>}
                </div>
              )}

              {transactionRows.length === 0 ? (
                <div className="user-page-empty-state">
                  <ShoppingBag size={30} />
                  <p>No orders yet.</p>
                </div>
              ) : (
                <div className="order-table order-table--standalone">
                  <div className="order-table__head">
                    <span>Product</span>
                    <span>Price</span>
                    <span>Quantity</span>
                    <span>Subtotal</span>
                    <span>Status</span>
                    <span>Bought</span>
                  </div>

                  {transactionRows.map((row) => (
                    <div key={row.key} className="order-table__row">
                      <div className="order-table__product">
                        <div className="order-table__cover">
                          {row.bookCoverImage ? (
                            <img
                              src={`/backend/uploads/books/${row.bookCoverImage}`}
                              alt={row.title}
                              className="order-table__cover-image"
                            />
                          ) : (
                            <div className="order-table__cover-fallback">
                              <ShoppingBag size={18} />
                            </div>
                          )}
                        </div>
                        <div className="order-table__product-copy">
                          <strong>{row.title}</strong>
                          <p>{row.author}</p>
                        </div>
                      </div>
                      <span className="order-table__value order-table__value--price">{formatPrice(row.price)}</span>
                      <span className="order-table__value order-table__value--quantity">{row.quantity}</span>
                      <span className="order-table__value order-table__value--subtotal">{formatPrice(row.subtotal)}</span>
                      <span className="order-table__status">
                        <span className={`order-status order-status--${row.status}`}>{row.status}</span>
                        {row.status === 'paid' || row.status === 'pending' ? (
                          <button
                            type="button"
                            className="order-cancel-btn"
                            onClick={() => void handleCancelOrder(row.orderId, row.orderItemId, row.status)}
                            disabled={cancellingOrderId === row.orderItemId}
                          >
                            {cancellingOrderId === row.orderItemId ? 'Cancelling...' : 'Cancel order'}
                          </button>
                        ) : null}
                      </span>
                      <span className="order-table__date">{formatOrderTimestamp(row.boughtAt)}</span>
                    </div>
                  ))}
                </div>
              )}
            </section>
          )}
        </section>
      </main>

      <UserCartDrawer
        isOpen={isCartOpen}
        onClose={() => setIsCartOpen(false)}
        items={cartItems}
        addresses={currentUser?.addresses ?? []}
        subtotal={cartSubtotal}
        statusMessage={cartStatus}
        errorMessage={cartError}
        isSubmitting={isCheckingOut}
        onIncrement={handleIncrementItem}
        onDecrement={handleDecrementItem}
        onRemove={handleRemoveItem}
        onCheckout={handleCheckout}
      />

      {cancelNotice ? (
        <div className="user-success-popin" role="presentation">
          <div className="user-success-popin__card user-success-popin__card--narrow" role="dialog" aria-modal="true" aria-labelledby="cancel-order-title">
            <div>
              <strong id="cancel-order-title">{cancelNotice.title}</strong>
              <p>{cancelNotice.message}</p>
            </div>
            <button
              type="button"
              className="order-notice-btn"
              onClick={() => setCancelNotice(null)}
            >
              OK
            </button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
