import type { NextAuthOptions } from 'next-auth'
import CredentialsProvider from 'next-auth/providers/credentials'
import { scryptSync,timingSafeEqual } from 'node:crypto'
export const authOptions:NextAuthOptions={
 secret:process.env.NEXTAUTH_SECRET,
 session:{strategy:'jwt',maxAge:8*3600},
 pages:{signIn:'/auth/login'},
 providers:[CredentialsProvider({
 name:'Lặng Home Admin',credentials:{email:{label:'Email',type:'email'},password:{label:'Mật khẩu',type:'password'}},
 async authorize(credentials){
  const configured=process.env.ADMIN_PASSWORD_HASH || ''
  const [salt,hash]=configured.split(':')
  if(!process.env.ADMIN_EMAIL || !process.env.NEXTAUTH_SECRET || !salt || !hash || !/^[a-f0-9]{128}$/.test(hash) || !credentials?.password || credentials.password.length>256) return null
  const actual=scryptSync(credentials.password,salt,64)
  if(!timingSafeEqual(actual,Buffer.from(hash,'hex')) || credentials.email?.toLowerCase()!==process.env.ADMIN_EMAIL.toLowerCase())return null
  return {id:'lang-admin',name:'Quản lý Lặng',email:process.env.ADMIN_EMAIL.toLowerCase()}
 }
 })],
}

