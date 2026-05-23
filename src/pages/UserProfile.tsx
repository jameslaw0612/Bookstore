import { useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { CheckCircle2, Eye, EyeOff, Loader2, Plus, Save, ShieldCheck, Trash2 } from 'lucide-react'
import UserCartDrawer from '../components/UserCartDrawer'
import UserTopBar from '../components/UserTopBar'
import { COUNTRY_OPTIONS, getRegionOptions } from '../data/addressOptions'
import '../styles/Home.css'
import '../styles/UserPages.css'
import { apiRequest, getStoredUser, setStoredUser, type SessionUser } from '../utils/session'

interface ProfileResponse {
  success: boolean
  profile: SessionUser
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

interface CheckoutRequest {
  paymentMethod: string
  addressId: number
  selectedBookIds: number[]
}

interface AddressFormState {
  address_id: number
  country: string
  state_province: string
  city_town: string
  barangay: string
  apartment_unit: string
  street: string
  house_number: string
}

interface ProfileFormState {
  fname: string
  lname: string
  email: string
  phone: string
  addresses: AddressFormState[]
}

function toLocalPhoneNumber(value: string): string {
  const digits = value.replace(/\D/g, '')

  if (digits.startsWith('63') && digits.length === 12) {
    return `0${digits.slice(2)}`
  }

  if (digits.startsWith('9') && digits.length === 10) {
    return `0${digits}`
  }

  return digits.slice(0, 11)
}

function isValidLocalPhoneNumber(value: string): boolean {
  return /^09\d{9}$/.test(value)
}

function createEmptyAddress(): AddressFormState {
  return {
    address_id: 0,
    country: '',
    state_province: '',
    city_town: '',
    barangay: '',
    apartment_unit: '',
    street: '',
    house_number: '',
  }
}

function normalizeAddress(address?: Partial<AddressFormState>): AddressFormState {
  return {
    address_id: address?.address_id ?? 0,
    country: address?.country ?? '',
    state_province: address?.state_province ?? '',
    city_town: address?.city_town ?? '',
    barangay: address?.barangay ?? '',
    apartment_unit: address?.apartment_unit ?? '',
    street: address?.street ?? '',
    house_number: address?.house_number ?? '',
  }
}

function buildProfileForm(user: SessionUser | null): ProfileFormState {
  const incomingAddresses = user?.addresses?.length
    ? user.addresses
    : user?.address && Object.values(user.address).some(Boolean)
      ? [user.address]
      : []

  const addresses = incomingAddresses.slice(0, 2).map((address) => normalizeAddress(address))

  if (addresses.length === 0) {
    addresses.push(createEmptyAddress())
  }

  return {
    fname: user?.fname ?? '',
    lname: user?.lname ?? '',
    email: user?.email ?? '',
    phone: toLocalPhoneNumber(user?.phone ?? ''),
    addresses,
  }
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

function hasAnyAddressData(address: AddressFormState): boolean {
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

function formatAddressSummary(address?: AddressFormState): string {
  if (!address || !hasAnyAddressData(address)) {
    return 'Not set'
  }

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

function toComparableProfileForm(form: ProfileFormState) {
  return {
    fname: form.fname,
    lname: form.lname,
    email: form.email,
    phone: form.phone,
    addresses: form.addresses
      .filter((address) => hasAnyAddressData(address))
      .map((address) => ({
        address_id: address.address_id,
        country: address.country,
        state_province: address.state_province,
        city_town: address.city_town,
        barangay: address.barangay,
        apartment_unit: address.apartment_unit,
        street: address.street,
        house_number: address.house_number,
      })),
  }
}

export default function UserProfile() {
  const navigate = useNavigate()
  const [isCartOpen, setIsCartOpen] = useState(false)
  const [loading, setLoading] = useState(true)
  const [profileError, setProfileError] = useState('')
  const [profileSuccess, setProfileSuccess] = useState('')
  const [passwordSuccess, setPasswordSuccess] = useState('')
  const [showPasswordSuccessModal, setShowPasswordSuccessModal] = useState(false)
  const [savingProfile, setSavingProfile] = useState(false)
  const [savingPassword, setSavingPassword] = useState(false)
  const [showCurrentPassword, setShowCurrentPassword] = useState(false)
  const [showNewPassword, setShowNewPassword] = useState(false)
  const [showConfirmPassword, setShowConfirmPassword] = useState(false)
  const [cartItems, setCartItems] = useState<CartItem[]>([])
  const [cartStatus, setCartStatus] = useState('')
  const [cartError, setCartError] = useState('')
  const [isCheckingOut, setIsCheckingOut] = useState(false)
  const [currentUser, setCurrentUser] = useState<SessionUser | null>(() => getStoredUser())

  const storedUser = getStoredUser()

  const [profileForm, setProfileForm] = useState<ProfileFormState>(() => buildProfileForm(storedUser))
  const [savedProfileForm, setSavedProfileForm] = useState<ProfileFormState>(() => buildProfileForm(storedUser))

  const [passwordForm, setPasswordForm] = useState({
    currentPassword: '',
    newPassword: '',
    confirmPassword: '',
  })

  useEffect(() => {
    const loadProfile = async () => {
      try {
        setLoading(true)
        setProfileError('')
        const [data, cartData] = await Promise.all([
          apiRequest<ProfileResponse>('/api/users/profile'),
          apiRequest<{ success: boolean; cart: CartOrder | null }>('/api/orders/cart'),
        ])
        const nextProfileForm = buildProfileForm({
          ...data.profile,
          phone: toLocalPhoneNumber(data.profile.phone ?? ''),
        })
        setProfileForm(nextProfileForm)
        setSavedProfileForm(nextProfileForm)
        setCurrentUser(data.profile)
        setStoredUser(data.profile)
        setCartItems(mapCartOrderToItems(cartData.cart))
      } catch (error) {
        setProfileError(error instanceof Error ? error.message : 'Failed to load profile')
      } finally {
        setLoading(false)
      }
    }

    void loadProfile()
  }, [])

  useEffect(() => {
    if (!passwordSuccess) {
      return
    }

    setShowPasswordSuccessModal(true)
    const timeoutId = window.setTimeout(() => {
      setShowPasswordSuccessModal(false)
      setPasswordSuccess('')
    }, 2200)

    return () => window.clearTimeout(timeoutId)
  }, [passwordSuccess])

  const updateAddressField = (index: number, field: keyof AddressFormState, value: string | number) => {
    setProfileForm((current) => {
      const addresses = current.addresses.map((address, addressIndex) => {
        if (addressIndex !== index) {
          return address
        }

        const nextAddress = {
          ...address,
          [field]: value,
        } as AddressFormState

        if (field === 'country') {
          const regionOptions = getRegionOptions(String(value))
          if (regionOptions.length > 0 && !regionOptions.includes(nextAddress.state_province)) {
            nextAddress.state_province = ''
          }
        }

        return nextAddress
      })

      return { ...current, addresses }
    })
  }

  const addSecondaryAddress = () => {
    setProfileForm((current) => {
      if (current.addresses.length >= 2) {
        return current
      }

      return {
        ...current,
        addresses: [...current.addresses, createEmptyAddress()],
      }
    })
  }

  const removeSecondaryAddress = () => {
    setProfileForm((current) => ({
      ...current,
      addresses: current.addresses.slice(0, 1),
    }))
  }

  const handleProfileSave = async (event: React.FormEvent) => {
    event.preventDefault()
    setProfileError('')
    setProfileSuccess('')

    if (!isValidLocalPhoneNumber(profileForm.phone)) {
      setProfileError('Phone number must be 11 digits and start with 09')
      return
    }

    const addresses = profileForm.addresses.filter((address) => hasAnyAddressData(address))

    try {
      setSavingProfile(true)
      const data = await apiRequest<ProfileResponse & { message: string }>('/api/users/profile', {
        method: 'PUT',
        body: JSON.stringify({
          fname: profileForm.fname,
          lname: profileForm.lname,
          email: profileForm.email,
          phone: profileForm.phone,
          addresses,
        }),
      })
      const nextProfileForm = buildProfileForm({
        ...data.profile,
        phone: toLocalPhoneNumber(data.profile.phone ?? ''),
      })
      setProfileForm(nextProfileForm)
      setSavedProfileForm(nextProfileForm)
      setCurrentUser(data.profile)
      setStoredUser(data.profile)
      setProfileSuccess(data.message)
    } catch (error) {
      setProfileError(error instanceof Error ? error.message : 'Failed to update profile')
    } finally {
      setSavingProfile(false)
    }
  }

  const handlePasswordSave = async (event: React.FormEvent) => {
    event.preventDefault()
    setProfileError('')
    setPasswordSuccess('')

    if (passwordForm.newPassword !== passwordForm.confirmPassword) {
      setProfileError('New password and confirmation do not match')
      return
    }

    if (passwordForm.newPassword.length < 8) {
      setProfileError('New password must be at least 8 characters long')
      return
    }

    try {
      setSavingPassword(true)
      const data = await apiRequest<{ success: boolean; message: string }>('/api/users/change-password', {
        method: 'PUT',
        body: JSON.stringify({
          current_password: passwordForm.currentPassword,
          new_password: passwordForm.newPassword,
        }),
      })
      setPasswordForm({
        currentPassword: '',
        newPassword: '',
        confirmPassword: '',
      })
      setPasswordSuccess(data.message)
    } catch (error) {
      setProfileError(error instanceof Error ? error.message : 'Failed to update password')
    } finally {
      setSavingPassword(false)
    }
  }

  const primaryAddress = profileForm.addresses[0] ?? createEmptyAddress()
  const secondaryAddress = profileForm.addresses[1]
  const cartCount = useMemo(
    () => cartItems.reduce((sum, item) => sum + item.quantity, 0),
    [cartItems],
  )
  const cartSubtotal = useMemo(
    () => cartItems.reduce((sum, item) => sum + item.price * item.quantity, 0),
    [cartItems],
  )
  const hasProfileChanges =
    JSON.stringify(toComparableProfileForm(profileForm)) !== JSON.stringify(toComparableProfileForm(savedProfileForm))

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
    } catch (error) {
      setCartError(error instanceof Error ? error.message : 'Failed to update cart')
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
    } catch (error) {
      setCartError(error instanceof Error ? error.message : 'Failed to update cart')
    }
  }

  const handleRemoveItem = async (bookId: number) => {
    setCartStatus('')
    setCartError('')
    const nextItems = cartItems.filter((item) => item.book_id !== bookId)

    try {
      await syncCartItems(nextItems)
    } catch (error) {
      setCartError(error instanceof Error ? error.message : 'Failed to update cart')
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
      navigate('/transactions', {
        state: {
          checkoutMessage: response.message,
          orderId: response.order.order_id,
        },
      })
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Unable to place order'
      setCartError(message)
      throw error
    } finally {
      setIsCheckingOut(false)
    }
  }

  const renderAddressCard = (address: AddressFormState, index: number, heading: string, allowRemove: boolean) => {
    const regionOptions = getRegionOptions(address.country)

    return (
      <section className="user-panel-card address-panel-card" key={heading}>
        <div className="user-panel-heading address-panel-heading">
          <h2>{heading}</h2>
          {allowRemove ? (
            <button type="button" className="address-remove-btn" onClick={removeSecondaryAddress}>
              <Trash2 size={16} />
              <span>Remove</span>
            </button>
          ) : null}
        </div>

        <div className="user-form-grid">
          <label>
            <span>Country</span>
            <select
              value={address.country}
              onChange={(event) => updateAddressField(index, 'country', event.target.value)}
            >
              <option value="">Select country</option>
              {COUNTRY_OPTIONS.map((country) => (
                <option key={country} value={country}>
                  {country}
                </option>
              ))}
            </select>
          </label>

          <label>
            <span>State / Province</span>
            {regionOptions.length > 0 ? (
              <select
                value={address.state_province}
                onChange={(event) => updateAddressField(index, 'state_province', event.target.value)}
                disabled={!address.country}
              >
                <option value="">Select state / province</option>
                {regionOptions.map((region) => (
                  <option key={region} value={region}>
                    {region}
                  </option>
                ))}
              </select>
            ) : (
              <input
                value={address.state_province}
                onChange={(event) => updateAddressField(index, 'state_province', event.target.value)}
                placeholder={address.country ? 'Enter state or province' : 'Select a country first'}
                disabled={!address.country}
              />
            )}
          </label>

          <label>
            <span>City / Town</span>
            <input
              value={address.city_town}
              onChange={(event) => updateAddressField(index, 'city_town', event.target.value)}
            />
          </label>

          <label>
            <span>Barangay (Optional)</span>
            <input
              value={address.barangay}
              onChange={(event) => updateAddressField(index, 'barangay', event.target.value)}
            />
          </label>

          <label>
            <span>Street</span>
            <input
              value={address.street}
              onChange={(event) => updateAddressField(index, 'street', event.target.value)}
            />
          </label>

          <label>
            <span>House Number</span>
            <input
              value={address.house_number}
              onChange={(event) => updateAddressField(index, 'house_number', event.target.value)}
            />
          </label>

          <label>
            <span>Apartment / Unit (Optional)</span>
            <input
              value={address.apartment_unit}
              onChange={(event) => updateAddressField(index, 'apartment_unit', event.target.value)}
            />
          </label>
        </div>
      </section>
    )
  }

  return (
    <div className="home-container">
      <UserTopBar
        activeNav="profile"
        cartOpen={isCartOpen}
        cartCount={cartCount}
        onCartClick={() => setIsCartOpen((prev) => !prev)}
      />

      <main className="home-main">
        <section className="user-page-shell">
          {loading ? (
            <div className="user-page-placeholder">
              <Loader2 className="user-page-spinner" size={32} />
              <p>Loading your profile...</p>
            </div>
          ) : (
            <div className="profile-layout">
              <aside className="profile-summary-card">
                <div className="profile-summary-avatar">
                  {(profileForm.fname[0] ?? 'U').toUpperCase()}
                </div>
                <h2>{profileForm.fname} {profileForm.lname}</h2>
                <p>{profileForm.email}</p>
                <dl className="profile-summary-list">
                  <div>
                    <dt>Phone</dt>
                    <dd>{profileForm.phone || 'Not set'}</dd>
                  </div>
                  <div>
                    <dt>Primary Address</dt>
                    <dd>{formatAddressSummary(primaryAddress)}</dd>
                  </div>
                  {secondaryAddress && hasAnyAddressData(secondaryAddress) ? (
                    <div>
                      <dt>Secondary Address</dt>
                      <dd>{formatAddressSummary(secondaryAddress)}</dd>
                    </div>
                  ) : null}
                </dl>
              </aside>

              <div className="profile-forms">
                {(profileError || profileSuccess) && (
                  <div className="user-page-messages">
                    {profileError && <p className="user-page-message error">{profileError}</p>}
                    {profileSuccess && <p className="user-page-message success"><CheckCircle2 size={16} /> {profileSuccess}</p>}
                  </div>
                )}

                <form className="profile-form-stack" onSubmit={handleProfileSave}>
                  <section className="user-panel-card">
                    <div className="user-panel-heading">
                      <h2>Profile Details</h2>
                    </div>

                    <div className="user-form-grid">
                      <label>
                        <span>First Name</span>
                        <input
                          value={profileForm.fname}
                          onChange={(event) => setProfileForm((current) => ({ ...current, fname: event.target.value }))}
                          required
                        />
                      </label>
                      <label>
                        <span>Last Name</span>
                        <input
                          value={profileForm.lname}
                          onChange={(event) => setProfileForm((current) => ({ ...current, lname: event.target.value }))}
                          required
                        />
                      </label>
                      <label>
                        <span>Email</span>
                        <input
                          type="email"
                          value={profileForm.email}
                          onChange={(event) => setProfileForm((current) => ({ ...current, email: event.target.value }))}
                          required
                        />
                      </label>
                      <label>
                        <span>Phone</span>
                        <input
                          value={profileForm.phone}
                          onChange={(event) =>
                            setProfileForm((current) => ({
                              ...current,
                              phone: event.target.value.replace(/\D/g, '').slice(0, 11),
                            }))
                          }
                          placeholder="09123456789"
                          inputMode="numeric"
                          pattern="09[0-9]{9}"
                          required
                        />
                      </label>
                    </div>
                  </section>

                  {renderAddressCard(primaryAddress, 0, 'Primary Address', false)}

                  {secondaryAddress ? renderAddressCard(secondaryAddress, 1, 'Secondary Address', true) : null}

                  <div className="profile-form-actions">
                    {!secondaryAddress ? (
                      <button type="button" className="address-add-btn" onClick={addSecondaryAddress}>
                        <Plus size={16} />
                        <span>Add Secondary Address</span>
                      </button>
                    ) : <div />}

                    <button type="submit" className="user-page-primary-btn" disabled={savingProfile || !hasProfileChanges}>
                      {savingProfile ? <Loader2 className="spin" size={18} /> : <Save size={18} />}
                      <span>{savingProfile ? 'Saving...' : 'Save Profile'}</span>
                    </button>
                  </div>
                </form>

                <form className="user-panel-card" onSubmit={handlePasswordSave}>
                  <div className="user-panel-heading">
                    <h2>Change Password</h2>
                  </div>

                  <div className="user-form-grid user-form-grid--stacked">
                    <label>
                      <span>Current Password</span>
                      <div className="password-input-wrapper">
                        <input
                          type={showCurrentPassword ? 'text' : 'password'}
                          autoComplete="current-password"
                          value={passwordForm.currentPassword}
                          onChange={(event) => setPasswordForm((current) => ({ ...current, currentPassword: event.target.value }))}
                          required
                        />
                        <button
                          type="button"
                          className="password-toggle-btn"
                          onClick={() => setShowCurrentPassword((current) => !current)}
                          title={showCurrentPassword ? 'Hide password' : 'Show password'}
                        >
                          {showCurrentPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                        </button>
                      </div>
                    </label>
                    <label>
                      <span>New Password</span>
                      <div className="password-input-wrapper">
                        <input
                          type={showNewPassword ? 'text' : 'password'}
                          autoComplete="new-password"
                          value={passwordForm.newPassword}
                          onChange={(event) => setPasswordForm((current) => ({ ...current, newPassword: event.target.value }))}
                          required
                        />
                        <button
                          type="button"
                          className="password-toggle-btn"
                          onClick={() => setShowNewPassword((current) => !current)}
                          title={showNewPassword ? 'Hide password' : 'Show password'}
                        >
                          {showNewPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                        </button>
                      </div>
                    </label>
                    <label>
                      <span>Confirm New Password</span>
                      <div className="password-input-wrapper">
                        <input
                          type={showConfirmPassword ? 'text' : 'password'}
                          autoComplete="new-password"
                          value={passwordForm.confirmPassword}
                          onChange={(event) => setPasswordForm((current) => ({ ...current, confirmPassword: event.target.value }))}
                          required
                        />
                        <button
                          type="button"
                          className="password-toggle-btn"
                          onClick={() => setShowConfirmPassword((current) => !current)}
                          title={showConfirmPassword ? 'Hide password' : 'Show password'}
                        >
                          {showConfirmPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                        </button>
                      </div>
                    </label>
                  </div>

                  <button type="submit" className="user-page-secondary-btn" disabled={savingPassword}>
                    {savingPassword ? <Loader2 className="spin" size={18} /> : <ShieldCheck size={18} />}
                    <span>{savingPassword ? 'Updating...' : 'Update Password'}</span>
                  </button>
                </form>
              </div>
            </div>
          )}
        </section>
      </main>

      {showPasswordSuccessModal && (
        <div className="user-success-popin" role="status" aria-live="polite">
          <div className="user-success-popin__card">
            <CheckCircle2 size={30} />
            <div>
              <strong>Password Updated</strong>
              <p>{passwordSuccess || 'Your password has been changed successfully.'}</p>
            </div>
          </div>
        </div>
      )}

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
    </div>
  )
}
