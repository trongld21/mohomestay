'use client'
import { useState,useEffect } from 'react'
export type Availability = {connected:boolean;onlineBooking:boolean;terms:string;overnight:{checkIn:string;checkOut:string};error?:string;rooms:{id:string;active?:boolean;intervals:{start:string;end:string;status:string}[]}[]}
export function useAvailability(date:string) {
 const [data,setData]=useState<Availability|null>(null)
 const [loading,setLoading]=useState(true)
 const [revision,setRevision]=useState(0)
 useEffect(()=>{
  const controller=new AbortController()
  setLoading(true);setData(null)
  fetch('/api/availability?date='+encodeURIComponent(date),{signal:controller.signal,cache:'no-store'}).then(r=>r.json()).then(result=>{if(!controller.signal.aborted)setData(result)}).catch(()=>{if(!controller.signal.aborted)setData({connected:false,onlineBooking:false,terms:'',overnight:{checkIn:'',checkOut:''},rooms:[],error:'Không tải được lịch. Vui lòng thử lại.'})}).finally(()=>{if(!controller.signal.aborted)setLoading(false)})
  return ()=>controller.abort()
 },[date,revision])
 return {data,loading,refresh:()=>setRevision(v=>v+1)}
}

