export function loginValidationErrors({ email = '', password = '' } = {}) {
  const errors = { email: '', password: '' }
  const normalizedEmail = String(email).trim()

  if (!normalizedEmail) {
    errors.email = 'Email is required.'
  } else if (!/^\S+@\S+\.\S+$/.test(normalizedEmail)) {
    errors.email = 'Enter a valid email.'
  }

  if (!password) {
    errors.password = 'Password is required.'
  }

  return errors
}
