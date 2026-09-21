const {test}=require('node:test')
const assert=require('node:assert/strict')
const {quoteBooking,stayWindow,overlaps}=require('../.test-build/lib/booking.js')
const {generateSignature,verifySignature}=require('../.test-build/lib/payment.js')
const now=new Date('2026-09-09T00:00:00Z')
const input={roomId:'white',date:'2026-09-10',time:'14:00',stayPackage:'3h',numberOfGuests:2,guestName:'Khách thử',guestPhone:'0357907153',guestEmail:'',acceptTerms:true}
test('all nine package prices match the supplied rate card',()=>{
 const expected={pink:{'3h':200000,'6h':380000,overnight:330000},white:{'3h':150000,'6h':350000,overnight:250000},black:{'3h':180000,'6h':300000,overnight:320000}}
 for(const [roomId,prices] of Object.entries(expected))for(const [stayPackage,price] of Object.entries(prices)){
 const quote=quoteBooking({...input,roomId,stayPackage,totalPrice:1},{checkIn:'20:00',checkOut:'10:00'},now)
 assert.equal(quote.totalPrice,price)
 }
})
test('hourly booking crossing midnight retains the full timestamp',()=>{
 const {start,end}=stayWindow('2026-09-10','23:30','3h')
 assert.equal(start.toISOString(),'2026-09-10T16:30:00.000Z')
 assert.equal(end.toISOString(),'2026-09-10T19:30:00.000Z')
})
test('overnight uses configured times and the following day',()=>{
 const {start,end}=stayWindow('2026-12-31','01:00','overnight',{checkIn:'20:00',checkOut:'10:00'})
 assert.equal(start.toISOString(),'2026-12-31T13:00:00.000Z')
 assert.equal(end.toISOString(),'2027-01-01T03:00:00.000Z')
 assert.throws(()=>stayWindow('2026-09-10','14:00','overnight'))
})
test('adjacent bookings are allowed but overlapping windows are rejected',()=>{
 const a=stayWindow('2026-09-10','10:00','3h')
 const b=stayWindow('2026-09-10','13:00','3h')
 const c=stayWindow('2026-09-10','12:59','3h')
 assert.equal(overlaps(a.start,a.end,b.start,b.end),false)
 assert.equal(overlaps(a.start,a.end,c.start,c.end),true)
})
test('invalid dates, packages, guests, contact details and consent are rejected',()=>{
 for(const change of [{date:'2026-02-30'},{date:'invalid'},{time:'25:00'},{date:'2026-09-08'},{date:'2028-01-01'},{stayPackage:'bad'},{roomId:'unknown'},{numberOfGuests:3},{numberOfGuests:1.5},{numberOfGuests:'2'},{guestName:'A'},{guestPhone:'abc0357907153'},{guestEmail:'invalid'},{guestNote:'a'.repeat(1001)},{acceptTerms:false}])assert.throws(()=>quoteBooking({...input,...change},undefined,now),JSON.stringify(change))
})
test('official payOS webhook example verifies; tampering and malformed signatures fail',()=>{
 const data={orderCode:123,amount:3000,description:'VQRIO123',accountNumber:'12345678',reference:'TF230204212323',transactionDateTime:'2023-02-04 18:25:00',currency:'VND',paymentLinkId:'124c33293c43417ab7879e14c8d9eb18',code:'00',desc:'Thành công',counterAccountBankId:'',counterAccountBankName:'',counterAccountName:'',counterAccountNumber:'',virtualAccountName:'',virtualAccountNumber:''}
 const key='1a54716c8f0efb2744fb28b6e38b25da7f67a925d98bc1c18bd8faaecadd7675'
 const signature='412e915d2871504ed31be63c8f62a149a4410d34c4c42affc9006ef9917eaa03'
 assert.equal(verifySignature(data,signature,key),true)
 assert.equal(verifySignature({...data,amount:1},signature,key),false)
 assert.equal(verifySignature(data,'00',key),false)
 assert.equal(verifySignature(data,signature,''),false)
 assert.equal(generateSignature({b:2,a:1},key),generateSignature({a:1,b:2},key))
})

test('payment creation signs the exact amount and rejects mismatched gateway responses',async()=>{
 const {createPayment}=require('../.test-build/lib/payment.js')
 const previousFetch=global.fetch
 const keys=['PAYOS_CLIENT_ID','PAYOS_API_KEY','PAYOS_CHECKSUM_KEY','NEXT_PUBLIC_SITE_URL','BOOKING_TERMS']
 const previous=Object.fromEntries(keys.map(k=>[k,process.env[k]]))
 Object.assign(process.env,{PAYOS_CLIENT_ID:'test',PAYOS_API_KEY:'test',PAYOS_CHECKSUM_KEY:'test-key',NEXT_PUBLIC_SITE_URL:'https://example.test',BOOKING_TERMS:'test terms'})
 let tamper=false
 global.fetch=async(url,options)=>{
  assert.equal(url,'https://api-merchant.payos.vn/v2/payment-requests')
  const payload=JSON.parse(options.body)
  assert.equal(payload.amount,150000)
  assert.equal(new URL(payload.returnUrl).hash,'#secret-token')
  const signed={amount:payload.amount,cancelUrl:payload.cancelUrl,description:payload.description,orderCode:payload.orderCode,returnUrl:payload.returnUrl}
  assert.equal(verifySignature(signed,payload.signature,'test-key'),true)
  const data={amount:tamper?1:150000,orderCode:123,qrCode:'test-only-qr',checkoutUrl:'https://pay.payos.vn/test-only',paymentLinkId:'test'}
  return Response.json({code:'00',data,signature:generateSignature(data,'test-key')})
 }
 try{
  const input={orderCode:123,amount:150000,bookingCode:'LANG-1234567890',token:'secret-token',expires:new Date(Date.now()+900000)}
  assert.equal((await createPayment(input)).qrCode,'test-only-qr')
  tamper=true
  await assert.rejects(createPayment(input),/mismatch/)
 }finally{global.fetch=previousFetch;for(const k of keys){if(previous[k]===undefined)delete process.env[k];else process.env[k]=previous[k]}}
})
