import getpass
import hashlib
import secrets

password = getpass.getpass('Mật khẩu quản lý (ít nhất 12 ký tự): ')
if len(password) < 12:
    raise SystemExit('Mật khẩu cần ít nhất 12 ký tự.')
if password != getpass.getpass('Nhập lại mật khẩu: '):
    raise SystemExit('Mật khẩu không khớp.')
salt = secrets.token_hex(16)
digest = hashlib.scrypt(password.encode(), salt=salt.encode(), n=16384, r=8, p=1, dklen=64).hex()
print('Lưu giá trị sau vào ADMIN_PASSWORD_HASH:')
print(salt + ':' + digest)
