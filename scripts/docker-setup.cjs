const { readFileSync, writeFileSync } = require('node:fs')
const { randomBytes } = require('node:crypto')
const { resolve } = require('node:path')

const root = resolve(__dirname, '..')
const template = readFileSync(resolve(root, '.env.docker.example'), 'utf8')
const contents = template
  .replace('GENERATE_POSTGRES_PASSWORD', randomBytes(24).toString('hex'))
  .replace('GENERATE_NEXTAUTH_SECRET', randomBytes(32).toString('hex'))
try {
  writeFileSync(resolve(root, '.env.docker'), contents, { flag: 'wx', mode: 0o600 })
  console.log('Đã tạo .env.docker với mật khẩu ngẫu nhiên. .env.local được giữ nguyên.')
} catch (error) {
  if (error.code !== 'EEXIST') throw error
  console.log('.env.docker đã tồn tại; giữ nguyên cấu hình hiện tại.')
}
