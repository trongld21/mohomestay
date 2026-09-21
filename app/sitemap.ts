import type { MetadataRoute } from 'next'
export default function sitemap():MetadataRoute.Sitemap {const base=process.env.NEXT_PUBLIC_SITE_URL;if(!base)return [];return ['','/rooms','/rooms/pink','/rooms/white','/rooms/black','/calendar'].map(path=>({url:base+path,changeFrequency:'weekly' as const,priority:path===''?1:0.7}))}

