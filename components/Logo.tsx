export default function Logo({ light = false }: { light?: boolean }) {
return <span className={`brand ${light ? 'brand-light' : ''}`}><svg width="37" height="44" viewBox="0 0 44 52" fill="none" aria-hidden="true"><path d="M5 46V21Q5 17 8 14L22 2L38 16Q40 18 43 18M38 23V44C28 48 16 37 19 27C22 15 31 26 24 37C17 47 7 45 12 42C18 38 30 49 38 44" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"/></svg><span className="brand-name">LẶNG<span>H O M E</span></span></span>
}
