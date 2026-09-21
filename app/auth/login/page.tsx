'use client'
import { FormEvent,useState } from 'react'
import { signIn } from 'next-auth/react'
export default function LoginPage(){
 const [busy,setBusy]=useState(false);const [error,setError]=useState('')
 async function submit(e:FormEvent<HTMLFormElement>){e.preventDefault();setBusy(true);setError('');const f=new FormData(e.currentTarget);try{const result=await signIn('credentials',{email:f.get('email'),password:f.get('password'),redirect:false,callbackUrl:'/admin'});if(result?.ok)location.assign('/admin');else setError('Thông tin đăng nhập không đúng hoặc tài khoản quản lý chưa được cấu hình.')}catch{setError('Chưa thể đăng nhập. Bạn thử lại sau nhé.')}finally{setBusy(false)}}
 return <div className="container section"><div className="panel payment-container" style={{maxWidth:440}}><span className="eyebrow">DÀNH CHO CHỦ HOME</span><h1 style={{fontSize:36}}>Đăng nhập quản lý</h1><p className="muted">Khách đặt phòng không cần tạo tài khoản.</p><form onSubmit={submit} className="flex flex-col gap-5"><label className="field">Email<input name="email" type="email" autoComplete="username" required/></label><label className="field">Mật khẩu<input name="password" type="password" autoComplete="current-password" required maxLength={256}/></label>{error&&<p className="notice error" role="alert">{error}</p>}<button className="button" disabled={busy}>{busy?'Đang đăng nhập…':'Đăng nhập'}</button></form></div></div>
}

