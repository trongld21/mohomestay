'use client'
export default function ErrorPage({reset}:{reset:()=>void}){return <div className="container section text-center"><h1>Có chút gián đoạn.</h1><p className="muted">Bạn thử tải lại, hoặc gọi Lặng qua 0357 907 153 nhé.</p><button className="button" onClick={reset}>Thử lại</button></div>}

