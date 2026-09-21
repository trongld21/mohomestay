const {test,before,after}=require('node:test')
const assert=require('node:assert/strict')
const path=require('node:path')
const Module=require('node:module')
const target=process.env.TEST_DATABASE_URL
if(!target || !new URL(target).pathname.includes('langhome_test'))throw new Error('Set TEST_DATABASE_URL to an isolated database containing langhome_test in its name.')
process.env.DATABASE_URL=target
process.env.BOOKINGS_ENABLED='true'
process.env.PAYOS_CLIENT_ID='test'
process.env.PAYOS_API_KEY='test'
process.env.PAYOS_CHECKSUM_KEY='integration-test-key'
process.env.NEXT_PUBLIC_SITE_URL='https://example.test'
process.env.BOOKING_TERMS='Test-only terms'
process.env.OVERNIGHT_CHECK_IN='20:00'
process.env.OVERNIGHT_CHECK_OUT='10:00'
const resolve=Module._resolveFilename
Module._resolveFilename=function(request,parent,...rest){return resolve.call(this,request.startsWith('@/')?path.resolve(__dirname,'../.test-build',request.slice(2)):request,parent,...rest)}
const {db}=require('../.test-build/lib/db')
const payment=require('../.test-build/lib/payment')
// Only the outbound payment gateway is stubbed. Prisma and route handlers are real.
payment.createPayment=async(input)=>({qrCode:'test-only-qr',checkoutUrl:'https://pay.payos.vn/test-only',paymentLinkId:String(input.orderCode)})
const bookingRoute=require('../.test-build/app/api/bookings/route')
const availabilityRoute=require('../.test-build/app/api/availability/route')
const lookupRoute=require('../.test-build/app/api/bookings/lookup/route')
const webhookRoute=require('../.test-build/app/api/payments/webhook/route')
const date=new Date(Date.now()+7*86400000).toISOString().slice(0,10)
const input={roomId:'white',date,time:'14:00',stayPackage:'3h',numberOfGuests:2,guestName:'Khách thử nghiệm',guestPhone:'0357907153',guestEmail:'',acceptTerms:true,totalPrice:1}
const req=(data)=>new Request('https://example.test/api',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})
async function clean(){await db.payment.deleteMany();await db.booking.deleteMany();await db.roomAvailability.deleteMany()}
async function webhook(booking,overrides={},signatureOverride){const data={orderCode:Number(booking.orderCode),amount:booking.totalPrice,reference:'REF-'+booking.id,currency:'VND',code:'00',...overrides};return webhookRoute.POST(req({data,signature:signatureOverride||payment.generateSignature(data,process.env.PAYOS_CHECKSUM_KEY)}))}
before(async()=>{await clean();for(const id of ['pink','white','black'])await db.room.upsert({where:{id},update:{isActive:true},create:{id,name:id,slug:id,price:250000,maxGuests:2,bedrooms:1,bathrooms:1}})})
after(async()=>{await clean();await db.$disconnect()})
test('concurrent booking requests reserve the room only once; price is server-authoritative',async()=>{
 await clean()
 const results=await Promise.all([bookingRoute.POST(req(input)),bookingRoute.POST(req({...input,guestPhone:'0381234567'}))])
 assert.deepEqual(results.map(r=>r.status).sort(),[201,409])
 assert.equal(await db.booking.count(),1)
 const booking=await db.booking.findFirstOrThrow()
 assert.equal(booking.totalPrice,150000)
 const data=await results.find(r=>r.status===201).json()
 assert.equal(data.token.length,64)
})
test('availability is private and lookup requires correct phone or token',async()=>{
 const response=await availabilityRoute.GET(new Request('https://example.test/api/availability?date='+date))
 const body=await response.json()
 assert.equal(body.connected,true)
 assert.equal(body.rooms.find(r=>r.id==='white').intervals.length,1)
 const serialized=JSON.stringify(body)
 for(const privateField of ['guestName','guestPhone','guestEmail','accessToken','bookingCode'])assert.equal(serialized.includes(privateField),false)
 const booking=await db.booking.findFirstOrThrow()
 assert.equal((await lookupRoute.POST(req({code:booking.bookingCode,phone:'000'}))).status,404)
 assert.equal((await lookupRoute.POST(req({code:booking.bookingCode,token:booking.accessToken}))).status,200)
 assert.equal((await lookupRoute.POST(req({code:booking.bookingCode,phone:booking.guestPhone}))).status,200)
})
test('webhook rejects forged signatures and wrong amounts, then confirms idempotently',async()=>{
 const booking=await db.booking.findFirstOrThrow()
 assert.equal((await webhook(booking,{},'0'.repeat(64))).status,401)
 assert.equal((await webhook(booking,{amount:1})).status,400)
 assert.equal((await webhook(booking)).status,200)
 assert.equal((await webhook(booking)).status,200)
 const updated=await db.booking.findUniqueOrThrow({where:{id:booking.id}})
 assert.equal(updated.status,'CONFIRMED')
 assert.equal(updated.paymentStatus,'PAID')
 assert.equal(await db.payment.count({where:{bookingId:booking.id}}),1)
})
test('expired holds release inventory; late payment cannot displace the new guest',async()=>{
 await clean()
 assert.equal((await bookingRoute.POST(req(input))).status,201)
 const old=await db.booking.findFirstOrThrow()
 await db.booking.update({where:{id:old.id},data:{holdExpiresAt:new Date(Date.now()-60000)}})
 assert.equal((await bookingRoute.POST(req({...input,guestPhone:'0381234567'}))).status,201)
 assert.equal((await webhook(old)).status,200)
 const paidLate=await db.booking.findUniqueOrThrow({where:{id:old.id},include:{payment:true}})
 assert.equal(paidLate.status,'CANCELLED')
 assert.equal(paidLate.paymentStatus,'PAID')
 assert.match(paidLate.payment.failureReason,/đối soát/)
 const fresh=await db.booking.findFirstOrThrow({where:{id:{not:old.id}}})
 assert.equal(fresh.status,'PENDING')
})
test('adjacent slots are allowed and full-day maintenance blocks bookings',async()=>{
 await clean()
 assert.equal((await bookingRoute.POST(req(input))).status,201)
 assert.equal((await bookingRoute.POST(req({...input,time:'17:00'}))).status,201)
 assert.equal((await bookingRoute.POST(req({...input,time:'16:59',guestPhone:'0381234567'}))).status,409)
 await db.roomAvailability.create({data:{roomId:'black',date:new Date(date+'T00:00:00Z'),available:false}})
 assert.equal((await bookingRoute.POST(req({...input,roomId:'black'}))).status,409)
})
test('disabled bookings fail closed and never create fake reservations',async()=>{
 await clean();process.env.BOOKINGS_ENABLED='false'
 try{assert.equal((await bookingRoute.POST(req(input))).status,503);assert.equal(await db.booking.count(),0)}finally{process.env.BOOKINGS_ENABLED='true'}
})
test('a webhook arriving before QR persistence does not overwrite PAID',async()=>{
 await clean()
 const original=payment.createPayment
 payment.createPayment=async(input)=>{
  const booking=await db.booking.findUniqueOrThrow({where:{orderCode:BigInt(input.orderCode)}})
  assert.equal((await webhook(booking)).status,200)
  return {qrCode:'test-only-qr',checkoutUrl:'https://pay.payos.vn/test-only',paymentLinkId:String(input.orderCode)}
 }
 try{
  assert.equal((await bookingRoute.POST(req(input))).status,201)
  const booking=await db.booking.findFirstOrThrow({include:{payment:true}})
  assert.equal(booking.paymentStatus,'PAID')
  assert.equal(booking.payment.status,'PAID')
  assert.equal(booking.status,'CONFIRMED')
  assert.equal(booking.payment.qrCode,'test-only-qr')
 }finally{payment.createPayment=original}
})
test('gateway timeouts never report booking success and keep a bounded hold for reconciliation',async()=>{
 await clean()
 const original=payment.createPayment
 payment.createPayment=async()=>{throw new Error('Timeout')}
 try{
  const response=await bookingRoute.POST(req(input))
  assert.equal(response.status,502)
  const booking=await db.booking.findFirstOrThrow()
  assert.equal(booking.status,'PENDING')
  assert.equal(booking.paymentStatus,'UNPAID')
  assert.ok(booking.holdExpiresAt>new Date())
  assert.ok(booking.holdExpiresAt.getTime()-Date.now()<=15*60000)
 }finally{payment.createPayment=original}
})
