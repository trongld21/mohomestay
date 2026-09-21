'use client'
import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { signOut } from 'next-auth/react'
export function Logout(){return <button className="button outline small" onClick={()=>signOut({callbackUrl:'/auth/login'})}>Đăng xuất</button>}
export default function AdminActions({id,status}:{id:string;status:string}){
 const [error,setError]=useState('');const [busy,setBusy]=useState(false);const router=useRouter()
 const actions:Record<string,[string,string][]>= {PENDING:[['CANCELLED','Hủy đơn']],CONFIRMED:[['CHECKED_IN','Đã nhận phòng'],['CANCELLED','Hủy đơn']],CHECKED_IN:[['CHECKED_OUT','Đã trả phòng']]}
 async function update(next:string){if(next==='CANCELLED'&&!confirm('Hủy đơn này? Nếu đã thanh toán, bạn cần xử lý hoàn tiền riêng.'))return;setBusy(true);setError('');try{const r=await fetch('/api/admin/bookings',{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,status:next})});const d=await r.json();if(!r.ok)throw new Error(d.error);router.refresh()}catch(e){setError(e instanceof Error?e.message:'Không thể cập nhật')}finally{setBusy(false)}}
 return <div><div className="flex gap-2">{actions[status]?.map(([next,label])=><button key={next} className="button small outline" disabled={busy} onClick={()=>update(next)}>{label}</button>)}</div>{error&&<p className="notice error">{error}</p>}</div>
}

