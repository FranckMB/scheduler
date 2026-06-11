import { useState, type ChangeEvent, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { apiClient } from '@/shared/api/client'

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

type FormValues = {
  email: string
  password: string
  confirmPassword: string
  firstName: string
  lastName: string
  clubName: string
}

type FormErrors = Partial<Record<keyof FormValues, string>> & {
  api?: string
}

function validate(values: FormValues): FormErrors {
  const errors: FormErrors = {}

  if (!values.email.trim()) {
    errors.email = 'Email is required.'
  } else if (!emailPattern.test(values.email.trim())) {
    errors.email = 'Enter a valid email address.'
  }

  if (!values.password) {
    errors.password = 'Password is required.'
  } else if (values.password.length < 8) {
    errors.password = 'Password must be at least 8 characters.'
  }

  if (!values.confirmPassword) {
    errors.confirmPassword = 'Please confirm your password.'
  } else if (values.password !== values.confirmPassword) {
    errors.confirmPassword = 'Passwords do not match.'
  }

  if (!values.firstName.trim()) {
    errors.firstName = 'First name is required.'
  }

  if (!values.lastName.trim()) {
    errors.lastName = 'Last name is required.'
  }

  if (!values.clubName.trim()) {
    errors.clubName = 'Club name is required.'
  }

  return errors
}

async function readApiError(error: unknown): Promise<string> {
  if (typeof error === 'object' && null !== error && 'response' in error) {
    const response = (error as { response?: { json?: () => Promise<unknown> } }).response
    if (response && 'function' === typeof response.json) {
      try {
        const payload = (await response.json()) as { error?: string; message?: string }
        return payload.error ?? payload.message ?? 'Registration failed. Please try again.'
      } catch {
        return 'Registration failed. Please try again.'
      }
    }
  }

  return 'Registration failed. Please try again.'
}

export default function RegisterPage() {
  const navigate = useNavigate()
  const [values, setValues] = useState<FormValues>({
    email: '',
    password: '',
    confirmPassword: '',
    firstName: '',
    lastName: '',
    clubName: '',
  })
  const [errors, setErrors] = useState<FormErrors>({})
  const [isSubmitting, setIsSubmitting] = useState(false)

  const updateField = (field: keyof FormValues) => (event: ChangeEvent<HTMLInputElement>) => {
    const value = event.target.value
    setValues((current) => ({ ...current, [field]: value }))
    setErrors((current) => ({ ...current, [field]: undefined, api: undefined }))
  }

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()

    const nextErrors = validate(values)
    setErrors(nextErrors)

    if (Object.keys(nextErrors).length > 0) {
      return
    }

    setIsSubmitting(true)

    try {
      await apiClient.post('register', {
        json: {
          email: values.email.trim(),
          password: values.password,
          firstName: values.firstName.trim(),
          lastName: values.lastName.trim(),
          clubName: values.clubName.trim(),
        },
      })

      navigate('/login')
    } catch (error) {
      setErrors({ api: await readApiError(error) })
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <div className="flex min-h-[calc(100vh-4rem)] items-center justify-center bg-neutral-50 px-4 py-8">
      <div className="w-full max-w-lg rounded-2xl border border-neutral-200 bg-white p-8 shadow-sm">
        <div className="mb-8 space-y-2 text-center">
          <p className="text-sm font-semibold uppercase tracking-[0.24em] text-primary-600">Create account</p>
          <h2 className="text-3xl font-bold text-neutral-900">Register your club</h2>
          <p className="text-sm text-neutral-600">Set up your access, club identity, and onboarding in one step.</p>
        </div>

        {errors.api && (
          <div className="mb-6 rounded-lg border border-error-200 bg-error-50 px-4 py-3 text-sm text-error-700" role="alert">
            {errors.api}
          </div>
        )}

        <form className="space-y-5" onSubmit={handleSubmit} noValidate>
          <div>
            <label htmlFor="email" className="mb-1 block text-sm font-medium text-neutral-700">
              Email
            </label>
            <input
              id="email"
              type="email"
              value={values.email}
              onChange={updateField('email')}
              autoComplete="email"
              className="w-full rounded-lg border border-neutral-300 px-3 py-2.5 text-neutral-900 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20"
            />
            {errors.email && <p className="mt-1 text-sm text-error-600">{errors.email}</p>}
          </div>

          <div>
            <label htmlFor="password" className="mb-1 block text-sm font-medium text-neutral-700">
              Password
            </label>
            <input
              id="password"
              type="password"
              value={values.password}
              onChange={updateField('password')}
              autoComplete="new-password"
              className="w-full rounded-lg border border-neutral-300 px-3 py-2.5 text-neutral-900 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20"
            />
            {errors.password && <p className="mt-1 text-sm text-error-600">{errors.password}</p>}
          </div>

          <div>
            <label htmlFor="confirmPassword" className="mb-1 block text-sm font-medium text-neutral-700">
              Confirm password
            </label>
            <input
              id="confirmPassword"
              type="password"
              value={values.confirmPassword}
              onChange={updateField('confirmPassword')}
              autoComplete="new-password"
              className="w-full rounded-lg border border-neutral-300 px-3 py-2.5 text-neutral-900 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20"
            />
            {errors.confirmPassword && <p className="mt-1 text-sm text-error-600">{errors.confirmPassword}</p>}
          </div>

          <div>
            <label htmlFor="firstName" className="mb-1 block text-sm font-medium text-neutral-700">
              First name
            </label>
            <input
              id="firstName"
              type="text"
              value={values.firstName}
              onChange={updateField('firstName')}
              autoComplete="given-name"
              className="w-full rounded-lg border border-neutral-300 px-3 py-2.5 text-neutral-900 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20"
            />
            {errors.firstName && <p className="mt-1 text-sm text-error-600">{errors.firstName}</p>}
          </div>

          <div>
            <label htmlFor="lastName" className="mb-1 block text-sm font-medium text-neutral-700">
              Last name
            </label>
            <input
              id="lastName"
              type="text"
              value={values.lastName}
              onChange={updateField('lastName')}
              autoComplete="family-name"
              className="w-full rounded-lg border border-neutral-300 px-3 py-2.5 text-neutral-900 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20"
            />
            {errors.lastName && <p className="mt-1 text-sm text-error-600">{errors.lastName}</p>}
          </div>

          <div>
            <label htmlFor="clubName" className="mb-1 block text-sm font-medium text-neutral-700">
              Club name
            </label>
            <input
              id="clubName"
              type="text"
              value={values.clubName}
              onChange={updateField('clubName')}
              autoComplete="organization"
              className="w-full rounded-lg border border-neutral-300 px-3 py-2.5 text-neutral-900 outline-none transition focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20"
            />
            {errors.clubName && <p className="mt-1 text-sm text-error-600">{errors.clubName}</p>}
          </div>

          <button
            type="submit"
            disabled={isSubmitting}
            className="w-full rounded-lg bg-primary-600 px-4 py-3 font-semibold text-white transition hover:bg-primary-700 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {isSubmitting ? 'Creating account…' : 'Create account'}
          </button>
        </form>
      </div>
    </div>
  )
}
