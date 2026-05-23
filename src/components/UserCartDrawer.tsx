import { useEffect, useMemo, useState } from 'react'
import { Loader2, ShoppingBag } from 'lucide-react'
import type { SessionUserAddress } from '../utils/session'
import { formatCurrency } from '../utils/format'
import '../styles/UserCartDrawer.css'

interface CartDrawerItem {
  book_id: number
  title: string
  author: string
  price: number
  stock_quantity: number
  quantity: number
  book_cover_image: string | null
}

interface CheckoutPayload {
  paymentMethod: string
  addressId: number
  selectedBookIds: number[]
}

interface UserCartDrawerProps {
  isOpen: boolean
  onClose: () => void
  items: CartDrawerItem[]
  addresses?: SessionUserAddress[]
  subtotal: number
  statusMessage?: string
  errorMessage?: string
  isSubmitting?: boolean
  onIncrement: (bookId: number) => void
  onDecrement: (bookId: number) => void
  onRemove: (bookId: number) => void
  onCheckout: (payload: CheckoutPayload) => void | Promise<void>
  onViewOrders?: () => void
}

const PAYMENT_OPTIONS = [
  {
    value: 'Cash on delivery',
    label: 'Cash on delivery',
    description: 'Pay with cash upon delivery.',
  },
  {
    value: 'Payments via Maya (Credit/Debit Card, G-Cash, Maya)',
    label: 'Payments via Maya (Credit/Debit Card, G-Cash, Maya)',
    description: 'Use supported cards, G-Cash, or Maya at checkout.',
  },
  {
    value: 'Paynamics (G-Cash, BPI Online, 7-Eleven, Dragonpay, etc.)',
    label: 'Paynamics (G-Cash, BPI Online, 7-Eleven, Dragonpay, etc.)',
    description: 'Choose from additional online and over-the-counter payment channels.',
  },
] as const

function hasAnyAddressData(address: SessionUserAddress): boolean {
  return [
    address.country,
    address.state_province,
    address.city_town,
    address.barangay,
    address.apartment_unit,
    address.street,
    address.house_number,
  ].some((value) => value.trim() !== '')
}

function formatAddress(address: SessionUserAddress): string {
  const parts = [
    address.house_number,
    address.street,
    address.apartment_unit,
    address.barangay,
    address.city_town,
    address.state_province,
    address.country,
  ].filter(Boolean)

  return parts.join(', ')
}

export default function UserCartDrawer({
  isOpen,
  onClose,
  items,
  addresses = [],
  subtotal,
  statusMessage = '',
  errorMessage = '',
  isSubmitting = false,
  onIncrement,
  onDecrement,
  onRemove,
  onCheckout,
}: UserCartDrawerProps) {
  const [showCheckoutReceipt, setShowCheckoutReceipt] = useState(false)
  const [selectedItemIds, setSelectedItemIds] = useState<number[]>([])
  const [selectedAddressId, setSelectedAddressId] = useState(0)
  const [selectedPaymentMethod, setSelectedPaymentMethod] = useState<string>(PAYMENT_OPTIONS[0].value)
  const [agreedToTerms, setAgreedToTerms] = useState(false)

  const availableAddresses = useMemo(
    () => addresses.filter((address) => (address.address_id ?? 0) > 0 && hasAnyAddressData(address)),
    [addresses],
  )

  useEffect(() => {
    setSelectedItemIds((current) =>
      current.filter((bookId) => items.some((item) => item.book_id === bookId)),
    )
  }, [items])

  useEffect(() => {
    const defaultAddressId = availableAddresses[0]?.address_id ?? 0
    setSelectedAddressId((current) =>
      availableAddresses.some((address) => address.address_id === current)
        ? current
        : defaultAddressId,
    )
  }, [availableAddresses])

  useEffect(() => {
    if (!showCheckoutReceipt) {
      setAgreedToTerms(false)
      setSelectedPaymentMethod(PAYMENT_OPTIONS[0].value)
    }
  }, [showCheckoutReceipt])

  const checkoutItems = useMemo(
    () => (
      selectedItemIds.length > 0
        ? items.filter((item) => selectedItemIds.includes(item.book_id))
        : items
    ),
    [items, selectedItemIds],
  )

  const checkoutSubtotal = useMemo(
    () => checkoutItems.reduce((sum, item) => sum + item.price * item.quantity, 0),
    [checkoutItems],
  )

  const hasSelectedItems = selectedItemIds.length > 0
  const displayedSubtotal = hasSelectedItems ? checkoutSubtotal : subtotal

  const handleConfirmCheckout = async () => {
    try {
      await onCheckout({
        paymentMethod: selectedPaymentMethod,
        addressId: selectedAddressId,
        selectedBookIds: hasSelectedItems ? checkoutItems.map((item) => item.book_id) : [],
      })
      setShowCheckoutReceipt(false)
    } catch {
      // Parent state already renders the checkout error message.
    }
  }

  const handleItemSelectionToggle = (bookId: number) => {
    setSelectedItemIds((current) =>
      current.includes(bookId)
        ? current.filter((id) => id !== bookId)
        : [...current, bookId],
    )
  }

  if (!isOpen) {
    return null
  }

  return (
    <>
      <button
        type="button"
        className="cart-drawer-overlay"
        aria-label="Close cart panel"
        onClick={onClose}
      />
      <aside className="cart-drawer" aria-label="Shopping cart">
        <div className="cart-drawer__header">
          <h2>Shopping cart</h2>
          <button
            type="button"
            className="cart-drawer__close"
            onClick={onClose}
            aria-label="Close cart panel"
          >
            CLOSE <span aria-hidden="true">x</span>
          </button>
        </div>

        <div className="cart-drawer__body">
          {errorMessage && <p className="cart-drawer__message cart-drawer__message--error">{errorMessage}</p>}
          {statusMessage && <p className="cart-drawer__message cart-drawer__message--success">{statusMessage}</p>}

          {items.length === 0 ? (
            <div className="cart-drawer__empty">
              <ShoppingBag size={34} />
              <p className="cart-drawer__empty-title">Your cart is empty.</p>
              <p className="cart-drawer__empty-copy">
                Add books from the catalog to prepare a checkout order.
              </p>
            </div>
          ) : (
            <div className="cart-drawer__items">
              {items.map((item) => (
                <article key={item.book_id} className="cart-drawer__item">
                  <label className="cart-drawer__item-select">
                    <input
                      type="checkbox"
                      checked={selectedItemIds.includes(item.book_id)}
                      onChange={() => handleItemSelectionToggle(item.book_id)}
                      aria-label={`Use ${item.title} for this order`}
                    />
                  </label>

                  <div className="cart-drawer__item-art">
                    {item.book_cover_image ? (
                      <img
                        src={`/backend/uploads/books/${item.book_cover_image}`}
                        alt={item.title}
                        className="cart-drawer__item-image"
                      />
                    ) : (
                      <div className="cart-drawer__item-fallback">
                        <ShoppingBag size={20} />
                      </div>
                    )}
                  </div>

                  <div className="cart-drawer__item-copy">
                    <div className="cart-drawer__item-copy-top">
                      <h3>{item.title}</h3>
                      <button
                        type="button"
                        className="cart-drawer__remove"
                        onClick={() => onRemove(item.book_id)}
                        aria-label={`Remove ${item.title} from cart`}
                      >
                        x
                      </button>
                    </div>
                    <p>{item.quantity} x {formatCurrency(item.price)}</p>
                    <div className="cart-drawer__quantity-row">
                      <div className="cart-drawer__quantity-controls" aria-label={`Quantity controls for ${item.title}`}>
                        <button
                          type="button"
                          className="cart-drawer__quantity-btn"
                          onClick={() => onDecrement(item.book_id)}
                          disabled={isSubmitting || item.quantity <= 1}
                          aria-label={`Decrease quantity of ${item.title}`}
                        >
                          -
                        </button>
                        <span className="cart-drawer__quantity-value">{item.quantity}</span>
                        <button
                          type="button"
                          className="cart-drawer__quantity-btn"
                          onClick={() => onIncrement(item.book_id)}
                          disabled={isSubmitting || item.quantity >= item.stock_quantity}
                          aria-label={`Increase quantity of ${item.title}`}
                        >
                          +
                        </button>
                      </div>
                      <span className="cart-drawer__stock-note">
                        {item.stock_quantity} in stock
                      </span>
                    </div>
                  </div>
                </article>
              ))}
            </div>
          )}
        </div>

        <div className="cart-drawer__footer">
          <p className="cart-drawer__subtotal-note">
            {hasSelectedItems
              ? 'Checked items will be placed in this order. Unchecked items stay in your cart.'
              : 'Subtotal includes all cart items by default.'}
          </p>
          <div className="cart-drawer__summary">
            <span>{hasSelectedItems ? 'Selected subtotal:' : 'Subtotal:'}</span>
            <strong>{formatCurrency(displayedSubtotal)}</strong>
          </div>
          <button
            type="button"
            className="cart-drawer__checkout"
            onClick={() => setShowCheckoutReceipt(true)}
            disabled={items.length === 0 || isSubmitting}
          >
            {isSubmitting ? <Loader2 className="spin" size={18} /> : null}
            <span>{isSubmitting ? 'PROCESSING...' : 'CHECKOUT'}</span>
          </button>
        </div>
      </aside>

      {showCheckoutReceipt && (
        <div className="cart-drawer__confirm-overlay" role="presentation">
          <div className="cart-drawer__receipt-dialog" role="dialog" aria-modal="true" aria-labelledby="cart-checkout-title">
            <div className="cart-drawer__receipt-header">
              <div>
                <h3 id="cart-checkout-title">Your order</h3>
                <p>Review your items, choose an address, and place the order as pending.</p>
              </div>
              <button
                type="button"
                className="cart-drawer__receipt-close"
                onClick={() => setShowCheckoutReceipt(false)}
                disabled={isSubmitting}
                aria-label="Close checkout receipt"
              >
                x
              </button>
            </div>

            <div className="cart-drawer__receipt-section">
              <div className="cart-drawer__receipt-table-head">
                <span>Product</span>
                <span>Subtotal</span>
              </div>

              <div className="cart-drawer__receipt-items">
                {checkoutItems.map((item) => (
                  <div key={item.book_id} className="cart-drawer__receipt-item">
                    <div>
                      <strong>{item.title}</strong>
                      <p>x {item.quantity}</p>
                    </div>
                    <span>{formatCurrency(item.price * item.quantity)}</span>
                  </div>
                ))}
              </div>

              <div className="cart-drawer__receipt-total cart-drawer__receipt-total--grand">
                <span>Total</span>
                <strong>{formatCurrency(checkoutSubtotal)}</strong>
              </div>
            </div>

            <div className="cart-drawer__receipt-section">
              <h4>Deliver to</h4>
              {availableAddresses.length === 0 ? (
                <p className="cart-drawer__receipt-empty">
                  Add a primary address in your profile before placing an order.
                </p>
              ) : (
                <div className="cart-drawer__option-list">
                  {availableAddresses.map((address, index) => (
                    <label key={address.address_id ?? index} className="cart-drawer__radio-card">
                      <input
                        type="radio"
                        name="checkout-address"
                        checked={selectedAddressId === address.address_id}
                        onChange={() => setSelectedAddressId(address.address_id ?? 0)}
                      />
                      <div>
                        <strong>{index === 0 ? 'Primary address' : 'Secondary address'}</strong>
                        <p>{formatAddress(address)}</p>
                      </div>
                    </label>
                  ))}
                </div>
              )}
            </div>

            <div className="cart-drawer__receipt-section">
              <h4>Mode of payment</h4>
              <div className="cart-drawer__option-list">
                {PAYMENT_OPTIONS.map((option) => (
                  <label key={option.value} className="cart-drawer__radio-card">
                    <input
                      type="radio"
                      name="checkout-payment"
                      checked={selectedPaymentMethod === option.value}
                      onChange={() => setSelectedPaymentMethod(option.value)}
                    />
                    <div>
                      <strong>{option.label}</strong>
                      <p>{option.description}</p>
                    </div>
                  </label>
                ))}
              </div>
            </div>

            <label className="cart-drawer__terms">
              <input
                type="checkbox"
                checked={agreedToTerms}
                onChange={(event) => setAgreedToTerms(event.target.checked)}
              />
              <span>I have read and agree to the website terms and conditions.</span>
            </label>

            <div className="cart-drawer__confirm-actions">
              <button
                type="button"
                className="cart-drawer__confirm-cancel"
                onClick={() => setShowCheckoutReceipt(false)}
                disabled={isSubmitting}
              >
                Cancel
              </button>
              <button
                type="button"
                className="cart-drawer__confirm-submit"
                onClick={() => void handleConfirmCheckout()}
                disabled={isSubmitting || availableAddresses.length === 0 || !agreedToTerms}
              >
                {isSubmitting ? <Loader2 className="spin" size={18} /> : null}
                <span>{isSubmitting ? 'Placing order...' : 'Place order'}</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  )
}
