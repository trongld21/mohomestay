// Animate only the illustration region; typography stays in the original static image.
document.querySelectorAll('[data-story-scene]').forEach(root => {
  const image = root.querySelector('img'), canvas = root.querySelector('canvas');
  const reduced = matchMedia('(prefers-reduced-motion: reduce)');
  const gl = canvas.getContext('webgl', {alpha:true, antialias:false});
  if (!gl) return;
  const vertex = `attribute vec2 position; varying vec2 uv; void main(){uv=vec2((position.x+1.0)*.5,(1.0-position.y)*.5);gl_Position=vec4(position,0.,1.);}`;
  const fragment = `precision mediump float;
    varying vec2 uv; uniform sampler2D illustration; uniform float time;
    float oval(vec2 p,vec2 c,vec2 r){return 1.-smoothstep(.7,1.,length((p-c)/r));}
    float hash(float n){return fract(sin(n*127.1)*43758.5453);}
    void main(){
      if(uv.y>.58)discard;
      float phase=mod(time,12.);
      float rain=smoothstep(2.7,4.,phase)*(1.-smoothstep(7.3,8.5,phase));
      float sunshine=smoothstep(8.,9.5,phase)*(1.-smoothstep(11.,12.,phase));
      float tree=oval(uv,vec2(.354,.319),vec2(.112,.12));
      // Protect the roof and house from foliage movement.
      tree*=1.-smoothstep(.39,.43,uv.x)*smoothstep(.30,.34,uv.y);
      float grass=oval(uv,vec2(.35,.548),vec2(.095,.027))+oval(uv,vec2(.71,.557),vec2(.032,.018));
      vec2 sampleUV=uv;
      float breeze=sin(time*2.2+uv.y*21.)*.0014+sin(time*3.7+uv.x*19.)*.0005;
      sampleUV.x+=breeze*(tree+grass*.6)*(1.+rain*.35);
      vec4 color=texture2D(illustration,sampleUV);
      float scene=oval(uv,vec2(.5,.37),vec2(.28,.215));
      color.rgb=mix(color.rgb,color.rgb*vec3(.965,.968,.976),rain*scene);
      float sun=oval(uv,vec2(.634,.263),vec2(.083,.083));
      color.rgb=mix(color.rgb,vec3(.94,.76,.48),sun*sunshine*.17);
      color.rgb=mix(color.rgb,vec3(1.,.94,.83),scene*sunshine*.035);
      // Fuller shower with longer strokes, clipped above the logo lettering.
      vec2 p=vec2(uv.x+uv.y*.12,uv.y);
      float column=floor(p.x*100.);
      float speed=.55+hash(column)*.25;
      float dropY=fract(p.y*3.-time*speed+hash(column+5.));
      float dropX=abs(fract(p.x*100.)-.5);
      float drops=(1.-smoothstep(.035,.13,dropX))*(1.-smoothstep(.025,.07,dropY));
      float rainArea=smoothstep(.18,.23,uv.x)*(1.-smoothstep(.75,.80,uv.x))*smoothstep(.14,.20,uv.y)*(1.-smoothstep(.55,.575,uv.y));
      color.rgb=mix(color.rgb,vec3(.54,.48,.41),drops*rain*rainArea*.44);
      // Small rings at the ground line fade with the shower.
      for(int i=0;i<3;i++){
        float f=float(i);float age=fract(time*.85+f*.31);
        vec2 center=vec2(.41+f*.115,.568);
        float ring=1.-smoothstep(.001,.003,abs(length((uv-center)*vec2(1.,3.))-age*.018));
        color.rgb=mix(color.rgb,vec3(.62,.54,.43),ring*(1.-age)*rain*.18);
      }
      gl_FragColor=color;
    }`;
  const compile = (type, source) => {
    const shader=gl.createShader(type);gl.shaderSource(shader,source);gl.compileShader(shader);
    if(!gl.getShaderParameter(shader,gl.COMPILE_STATUS))throw new Error('Scene shader unavailable');
    return shader;
  };
  let program;
  try {
    program=gl.createProgram();gl.attachShader(program,compile(gl.VERTEX_SHADER,vertex));gl.attachShader(program,compile(gl.FRAGMENT_SHADER,fragment));gl.linkProgram(program);
    if(!gl.getProgramParameter(program,gl.LINK_STATUS))return;
  } catch {return;}
  const start = () => {
    gl.useProgram(program);
    const buffer=gl.createBuffer();gl.bindBuffer(gl.ARRAY_BUFFER,buffer);gl.bufferData(gl.ARRAY_BUFFER,new Float32Array([-1,-1,1,-1,-1,1,-1,1,1,-1,1,1]),gl.STATIC_DRAW);
    const position=gl.getAttribLocation(program,'position');gl.enableVertexAttribArray(position);gl.vertexAttribPointer(position,2,gl.FLOAT,false,0,0);
    const texture=gl.createTexture();gl.bindTexture(gl.TEXTURE_2D,texture);
    gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MIN_FILTER,gl.LINEAR);gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MAG_FILTER,gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_WRAP_S,gl.CLAMP_TO_EDGE);gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_WRAP_T,gl.CLAMP_TO_EDGE);
    gl.texImage2D(gl.TEXTURE_2D,0,gl.RGBA,gl.RGBA,gl.UNSIGNED_BYTE,image);
    const uniform=gl.getUniformLocation(program,'time');
    let visible=false, elapsed=0, previous=0, frame=0;
    const resize=()=>{const size=Math.min(1000,Math.round(root.clientWidth*Math.min(devicePixelRatio,2)));canvas.width=canvas.height=size;gl.viewport(0,0,size,size);};
    new ResizeObserver(resize).observe(root);resize();
    const render=now=>{
      frame=0;
      if(!visible||reduced.matches||document.hidden){previous=0;return;}
      if(previous)elapsed+=Math.min((now-previous)/1000,.1);
      previous=now;
      gl.uniform1f(uniform,elapsed);gl.drawArrays(gl.TRIANGLES,0,6);
      root.dataset.scenePhase=elapsed%12<2.7?'wind':elapsed%12<8.5?'rain':'sun';
      frame=requestAnimationFrame(render);
    };
    const resume=()=>{canvas.hidden=reduced.matches;if(frame)cancelAnimationFrame(frame);previous=0;frame=requestAnimationFrame(render);};
    new IntersectionObserver(entries=>{visible=entries[0].isIntersecting;resume();},{threshold:.05}).observe(root);
    reduced.addEventListener('change',resume);document.addEventListener('visibilitychange',resume);
    canvas.addEventListener('webglcontextlost',event=>{event.preventDefault();cancelAnimationFrame(frame);canvas.hidden=true;});
    resume();
  };
  if(image.complete&&image.naturalWidth)start();else image.addEventListener('load',start,{once:true});
});
