export function generateBookingCode(): string {
  const timestamp = Date.now()
  const random = Math.random().toString(36).substring(2, 7).toUpperCase()
  return `BK${timestamp}${random}`
}

export function calculatePrice(
  basePrice: number,
  nights: number,
  discountPercent?: number
): number {
  let total = basePrice * nights
  if (discountPercent && discountPercent > 0) {
    total = total * (1 - discountPercent / 100)
  }
  return Math.round(total)
}

export function getDaysBetween(startDate: Date, endDate: Date): number {
  const milliseconds = endDate.getTime() - startDate.getTime()
  return Math.ceil(milliseconds / (1000 * 60 * 60 * 24))
}

export function formatDate(date: Date): string {
  return date.toLocaleDateString('vi-VN', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  })
}

export function formatCurrency(amount: number): string {
  return amount.toLocaleString('vi-VN', {
    style: 'currency',
    currency: 'VND',
  })
}

export function validateEmail(email: string): boolean {
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  return emailRegex.test(email)
}

export function validatePhone(phone: string): boolean {
  // Vietnamese phone number validation
  const phoneRegex = /(84|0[3|5|7|8|9])+([0-9]{8})\b/g
  return phoneRegex.test(phone)
}
