import { Link } from 'react-router-dom'
import '../styles/Auth.css'

export default function TermsAndConditions() {
  return (
    <div className="auth-shell">
      <div className="terms-page">
        <div className="terms-card">
          <p className="auth-form-eyebrow">Legal</p>
          <h1>Website Terms and Conditions</h1>
          <p className="terms-intro">
            These terms apply to the Online Bookstore Management System and govern how users
            register, browse, place orders, and manage their accounts on the platform.
          </p>

          <section className="terms-section">
            <h2>1. Account Registration</h2>
            <p>
              You must provide accurate and complete information when creating an account.
              You are responsible for keeping your login credentials secure and for activity
              that happens under your account.
            </p>
          </section>

          <section className="terms-section">
            <h2>2. Orders and Payments</h2>
            <p>
              All submitted orders are subject to stock availability and system validation.
              Payment methods provided through the bookstore must be used lawfully and with
              correct billing or delivery details.
            </p>
          </section>

          <section className="terms-section">
            <h2>3. Cancellations and Refunds</h2>
            <p>
              Orders marked as pending or paid may be cancelled according to the bookstore&apos;s
              current cancellation flow. Refunded payments, when applicable, are processed based
              on the order status recorded in the system.
            </p>
          </section>

          <section className="terms-section">
            <h2>4. User Conduct</h2>
            <p>
              Users must not misuse the website, attempt unauthorized access, submit false
              information, or interfere with the bookstore&apos;s operations, security, or data.
            </p>
          </section>

          <section className="terms-section">
            <h2>5. Privacy and Data</h2>
            <p>
              Personal information entered into the system is used to support registration,
              ordering, account management, and related bookstore operations. Sensitive fields
              may be stored using security controls implemented in the system.
            </p>
          </section>

          <section className="terms-section">
            <h2>6. Changes to the Service</h2>
            <p>
              The bookstore may update features, pricing, policies, or availability at any time
              to improve operations or maintain the platform.
            </p>
          </section>

          <div className="terms-actions">
            <Link to="/register" className="btn btn-primary">
              Back to Sign Up
            </Link>
          </div>
        </div>
      </div>
    </div>
  )
}
