require('@next/env').loadEnvConfig(process.cwd())
const { PrismaClient } = require('@prisma/client')
const db = new PrismaClient()
async function main() {
 for(const [id,name,price] of [['pink','Pink Room',330000],['white','White Room',250000],['black','Black Room',320000]]) {
  await db.room.upsert({where:{id},update:{},create:{id,name,slug:id,price,maxGuests:2,bedrooms:1,bathrooms:1,amenities:['Self check-in']}})
 }
}
main().catch(e=>{console.error(e.message);process.exitCode=1}).finally(()=>db.$disconnect())

