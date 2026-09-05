import * as THREE from 'three';
const E=window.EVO, R=E.route, {clamp,lerp,smoothstep,mod}=E;
const originalBuild=E.buildWorld;
E.buildWorld=function(renderer,quality){
  const w=originalBuild(renderer,quality), scene=w.scene, P=R.detailPlan, rnd=E.rng(61123);
  const UP=new THREE.Vector3(0,1,0), temp=new THREE.Matrix4(), quat=new THREE.Quaternion();
  const materials={}, textures=[];
  const material=(name,color,extra={})=>materials[name]||(materials[name]=new THREE.MeshStandardMaterial({name,color,roughness:0.88,...extra}));
  const canvasTexture=(width,height,draw)=>{
    const c=document.createElement('canvas');c.width=width;c.height=height;draw(c.getContext('2d'),width,height);
    const t=new THREE.CanvasTexture(c);t.colorSpace=THREE.SRGBColorSpace;t.anisotropy=Math.min(8,renderer.capabilities.getMaxAnisotropy());
    textures.push(t);return t;
  };
  // Shared materials use small repeatable canvases, not per-house image downloads.
  const masonryMap=canvasTexture(1024,1024,(c,W,H)=>{
    const r=E.rng(9812);c.fillStyle='#706e63';c.fillRect(0,0,W,H);
    for(let row=0;row<14;row++){
      const yy=row*H/14;let x=-170+(row%2)*65;
      while(x<W){
        const ww=85+r()*98,hh=H/14,shade=118+r()*35;
        const gr=c.createLinearGradient(x,yy,x+ww*.18,yy+hh);
        gr.addColorStop(0,`rgb(${shade+16},${shade+13},${shade+3})`);
        gr.addColorStop(.3,`rgb(${shade+3},${shade+1},${shade-6})`);
        gr.addColorStop(1,`rgb(${shade-9},${shade-8},${shade-14})`);
        c.beginPath();c.moveTo(x+5,yy+4+r()*3);c.lineTo(x+ww*.5,yy+3+r()*3);c.lineTo(x+ww-6,yy+5);c.lineTo(x+ww-3,yy+hh*.48);c.lineTo(x+ww-7,yy+hh-5);c.lineTo(x+ww*.34,yy+hh-4+r()*3);c.lineTo(x+4,yy+hh-5);c.closePath();
        c.fillStyle=gr;c.fill();c.strokeStyle='rgba(45,42,36,.24)';c.lineWidth=2;c.stroke();
        for(let k=0;k<9;k++){const xx=x+r()*ww,y=yy+r()*hh;c.fillStyle=r()<.5?'rgba(205,204,184,.13)':'rgba(64,61,51,.15)';c.beginPath();c.ellipse(xx,y,1+r()*8,.7+r()*4,r()*3,0,7);c.fill();}
        x+=ww;
      }
    }
    const image=c.getImageData(0,0,W,H),d=image.data;
    for(let i=0;i<d.length;i+=4){const x=(i/4)%W,y=Math.floor(i/4/W),noise=(r()-.5)*28,vein=(E.noise2(x*.024,y*.035)-.5)*26+(E.noise2(x*.13,y*.10)-.5)*12;d[i]+=noise+vein;d[i+1]+=noise+vein;d[i+2]+=noise+vein*.9;}
    c.putImageData(image,0,0);
  });
  masonryMap.wrapS=masonryMap.wrapT=THREE.RepeatWrapping;
  const normalFromMap=(t,strength=.6)=>{
    const source=t.image.getContext('2d').getImageData(0,0,t.image.width,t.image.height),W=source.width,H=source.height,d=source.data;
    const out=document.createElement('canvas');out.width=W;out.height=H;const c=out.getContext('2d'),im=c.createImageData(W,H);
    const height=(x,y)=>{const j=(((y+H)%H)*W+(x+W)%W)*4;return(d[j]+d[j+1]+d[j+2])/765;};
    for(let y=0;y<H;y++)for(let x=0;x<W;x++){
      const nx=(height(x-1,y)-height(x+1,y))*strength,ny=(height(x,y-1)-height(x,y+1))*strength,inv=1/Math.hypot(nx,ny,1),i=(y*W+x)*4;
      im.data[i]=128+nx*inv*127;im.data[i+1]=128+ny*inv*127;im.data[i+2]=128+inv*127;im.data[i+3]=255;
    }
    c.putImageData(im,0,0);const tex=new THREE.CanvasTexture(out);tex.wrapS=tex.wrapT=THREE.RepeatWrapping;tex.anisotropy=Math.min(8,renderer.capabilities.getMaxAnisotropy());textures.push(tex);return tex;
  };
  const masonryNormal=normalFromMap(masonryMap,2.4);
  const stone=material('cottage stone',0xd2cebf,{map:masonryMap,normalMap:masonryNormal,normalScale:new THREE.Vector2(.42,.42),roughness:.98});
  stone.userData.texScale=new THREE.Vector2(1/3.2,1/2);
  w.materials.stoneMat.color.set(0xb8b7a9);w.materials.stoneMat.normalScale.set(.40,.40);w.materials.stoneMat.roughness=.98;w.materials.stoneMat.needsUpdate=true;
  const slateMap=canvasTexture(512,512,(c,W,H)=>{
    const r=E.rng(845);c.fillStyle='#323733';c.fillRect(0,0,W,H);
    for(let row=0;row<12;row++)for(let col=-1;col<10;col++){
      const x=col*64+(row%2)*32,y=row*H/12,v=61+r()*23;
      c.fillStyle=`rgb(${v-2},${v+1},${v+3})`;c.fillRect(x+1,y+1,62,H/12-2);
      c.fillStyle='rgba(23,27,26,.6)';c.fillRect(x+1,y+H/12-3,62,2);
      c.strokeStyle='rgba(196,204,198,.11)';c.lineWidth=1;c.beginPath();c.moveTo(x+5,y+H/12-5);c.lineTo(x+55,y+H/12-6);c.stroke();
      for(let k=0;k<7;k++){c.fillStyle=r()<.6?'rgba(138,139,98,.19)':'rgba(170,178,171,.12)';c.fillRect(x+r()*60,y+r()*H/12,1+r()*3,1+r()*5);}
    }
  });
  slateMap.wrapS=slateMap.wrapT=THREE.RepeatWrapping;
  const slate=material('slate',0xd4d6cf,{map:slateMap,normalMap:normalFromMap(slateMap,1.4),normalScale:new THREE.Vector2(.32,.32),roughness:.96,side:THREE.DoubleSide});
  const renderMap=canvasTexture(256,512,(c,W,H)=>{
    const r=E.rng(555),im=c.createImageData(W,H);
    for(let y=0;y<H;y++)for(let x=0;x<W;x++){
      const fade=1-smoothstep(0,H*.30,H-y),stain=fade*(10+12*E.noise2(x*.04,y*.008)),v=219-stain+(r()-.5)*8,i=(y*W+x)*4;
      im.data[i]=v;im.data[i+1]=v-3;im.data[i+2]=v-12;im.data[i+3]=255;
    }c.putImageData(im,0,0);
  });
  const renderMat=material('pale render',0xc8c3b5,{map:renderMap,roughness:1});
  const mineralMap=canvasTexture(256,256,(c,W,H)=>{
    const r=E.rng(741),im=c.createImageData(W,H);
    for(let i=0;i<im.data.length;i+=4){const v=150+(r()-.5)*56;im.data[i]=v+3;im.data[i+1]=v+1;im.data[i+2]=v-4;im.data[i+3]=255;}c.putImageData(im,0,0);
    for(let k=0;k<300;k++){c.fillStyle='rgba(48,45,40,.23)';c.beginPath();c.ellipse(r()*W,r()*H,.6+r()*2,.5+r(),r()*3,0,7);c.fill();}
  });mineralMap.wrapS=mineralMap.wrapT=THREE.RepeatWrapping;
  const windowMap=canvasTexture(1024,384,(c,W,H)=>{
    for(let a=0;a<4;a++){
      const x=a*256;c.fillStyle='#273330';c.fillRect(x,0,256,H);
      const inside=c.createLinearGradient(0,0,0,H);inside.addColorStop(0,'#273337');inside.addColorStop(.5,'#3e4843');inside.addColorStop(1,'#242923');c.fillStyle=inside;c.fillRect(x+4,3,248,H-6);
      const curtain=['#b1afa0','#8e9892','#b9b3a1','#a29d92'][a];
      for(const side of [-1,1]){
        c.beginPath();const edge=side<0?x+4:x+252;c.moveTo(edge,0);c.lineTo(edge-side*58,0);c.bezierCurveTo(edge-side*70,H*.25,edge-side*27,H*.6,edge-side*40,H);c.lineTo(edge,H);c.closePath();c.fillStyle=curtain;c.fill();
        for(let k=1;k<5;k++){c.strokeStyle='rgba(50,51,44,.20)';c.lineWidth=3;c.beginPath();c.moveTo(edge-side*k*10,1);c.quadraticCurveTo(edge-side*k*6,H*.62,edge-side*k*8,H);c.stroke();}
      }
      // Soft interior sill and reflection, intentionally no luminous night windows.
      c.fillStyle='#444c40';c.fillRect(x+48,H*.72,155,12);c.fillStyle='#303c2f';c.fillRect(x+145,H*.68,20,H*.25);
      const ref=c.createLinearGradient(0,0,0,H);ref.addColorStop(0,'rgba(167,190,195,.27)');ref.addColorStop(.45,'rgba(104,124,113,.10)');ref.addColorStop(1,'rgba(20,37,30,.05)');c.fillStyle=ref;c.fillRect(x,0,256,H);
    }
  });
  const trim=material('window stone',0xb5ac96), white=material('painted joinery',0xe4dfd1);
  const glass=material('window reflections and curtains',0xffffff,{map:windowMap,roughness:.36,metalness:.08});
  const wood=material('weathered wood',0x71624e), metal=material('galvanised steel',0x969c99,{roughness:0.48,metalness:0.65});
  const dark=material('iron',0x292e2f,{roughness:0.68,metalness:0.22});
  const brick=material('chimney pots',0x905d40), red=material('postbox',0x941d22,{roughness:0.49,metalness:0.22});
  const concrete=material('kerb',0xb0afa5,{map:mineralMap,roughness:1}), gravel=material('gravel',0xa5977f,{map:mineralMap,roughness:1});
  const cream=material('road reflector',0xe8e6da), amber=material('amber reflector',0xdc9145);
  const boxes=new THREE.BoxGeometry(1,1,1), poleGeo=new THREE.CylinderGeometry(1,1,1,8);

  // Merge static furniture by material and geographic sector. No hundreds of
  // individual window/rail/brick draw calls, and distant sectors can be culled.
  class Batch {
    constructor(){this.groups=new Map();this.parts=0;}
    add(geo,mat,matrix){
      if(!geo.boundingSphere)geo.computeBoundingSphere();
      const center=geo.boundingSphere.center.clone().applyMatrix4(matrix);
      const key=`${mat.id}:${Math.floor(center.x/96)}:${Math.floor(center.z/96)}`;
      if(!this.groups.has(key))this.groups.set(key,{mat,p:[],n:[],uv:[],ix:[],count:0});
      const g=this.groups.get(key),pa=geo.attributes.position,na=geo.attributes.normal,ua=geo.attributes.uv;
      const nmat=new THREE.Matrix3().getNormalMatrix(matrix),p=new THREE.Vector3(),n=new THREE.Vector3();
      for(let i=0;i<pa.count;i++){
        p.fromBufferAttribute(pa,i).applyMatrix4(matrix);n.fromBufferAttribute(na,i).applyMatrix3(nmat).normalize();
        g.p.push(p.x,p.y,p.z);g.n.push(n.x,n.y,n.z);g.uv.push(ua?ua.getX(i):0,ua?ua.getY(i):0);
      }
      const idx=geo.index;
      if(idx)for(let i=0;i<idx.count;i++)g.ix.push(g.count+idx.getX(i));else for(let i=0;i<pa.count;i++)g.ix.push(g.count+i);
      g.count+=pa.count;this.parts++;
    }
    finish(){
      const meshes=[];
      for(const g of this.groups.values()){
        const geo=new THREE.BufferGeometry();geo.setAttribute('position',new THREE.Float32BufferAttribute(g.p,3));geo.setAttribute('normal',new THREE.Float32BufferAttribute(g.n,3));geo.setAttribute('uv',new THREE.Float32BufferAttribute(g.uv,2));geo.setIndex(g.ix);geo.computeBoundingSphere();
        const m=new THREE.Mesh(geo,g.mat);m.name='detail: '+g.mat.name;m.castShadow=g.mat.userData.noShadow!==true;m.receiveShadow=true;m.userData.detailDistance=quality.coarse?340:470;scene.add(m);meshes.push(m);
      }
      return meshes;
    }
  }
  const batch=new Batch();
  function object(geo,mat,x,y,z,sx=1,sy=1,sz=1,yaw=0,parent=null){
    const m=new THREE.Matrix4().compose(new THREE.Vector3(x,y,z),new THREE.Quaternion().setFromAxisAngle(UP,yaw),new THREE.Vector3(sx,sy,sz));
    if(parent)m.premultiply(parent);
    if(mat.userData.texScale && geo===boxes){
      const g=geo.clone(),pa=g.attributes.position,na=g.attributes.normal,uv=g.attributes.uv,scale=mat.userData.texScale;
      for(let i=0;i<pa.count;i++){
        const ax=Math.abs(na.getX(i)),ay=Math.abs(na.getY(i));
        const u=ax>.5?pa.getZ(i)*sz:pa.getX(i)*sx;
        const v=ay>.5?pa.getZ(i)*sz:pa.getY(i)*sy;
        uv.setXY(i,u*scale.x,v*scale.y);
      }
      batch.add(g,mat,m);g.dispose();
    }else batch.add(geo,mat,m);
  }
  const box=(mat,x,y,z,sx,sy,sz,yaw=0,parent=null)=>object(boxes,mat,x,y,z,sx,sy,sz,yaw,parent);
  function beam(mat,a,b,r=0.04,parent=null){
    const av=new THREE.Vector3(...a),bv=new THREE.Vector3(...b),delta=bv.clone().sub(av),len=delta.length();
    const m=new THREE.Matrix4().compose(av.add(bv).multiplyScalar(.5),new THREE.Quaternion().setFromUnitVectors(UP,delta.normalize()),new THREE.Vector3(r,len,r));
    if(parent)m.premultiply(parent);batch.add(poleGeo,mat,m);
  }
  const at=(s,d,y=0)=>{const g=w.groundAt(s,d);return new THREE.Vector3(g.x,g.y+y,g.z);};
  const road=(s,d,y=.012)=>R.point(s,d,y,new THREE.Vector3());
  // Sample the triangulated verge, not just its underlying height function.
  const vergeOffsets=[3.02,3.5,4.2,5,6,7.5,9.5,12,16,22,30];
  function renderedGround(s,d,raised=0){
    if(Math.abs(d)<=3.1)return road(s,d,raised);
    const ad=Math.abs(d),side=Math.sign(d),g=w.groundAt(s,d);
    if(ad>=30)return new THREE.Vector3(g.x,g.y+raised,g.z);
    const step=R.SAMPLE*2,s0=Math.floor(mod(s,R.length)/step)*step,s1=s0+step;
    let j=0;while(j<vergeOffsets.length-2&&vergeOffsets[j+1]<ad)j++;
    const d0=side*vergeOffsets[j],d1=side*vergeOffsets[j+1];
    const a=w.groundAt(s0,d0),b=w.groundAt(s1,d0),c=w.groundAt(s1,d1),e=w.groundAt(s0,d1);
    function bary(a,b,c){
      const det=(b.z-c.z)*(a.x-c.x)+(c.x-b.x)*(a.z-c.z);
      if(Math.abs(det)<1e-8)return null;
      const u=((b.z-c.z)*(g.x-c.x)+(c.x-b.x)*(g.z-c.z))/det,v=((c.z-a.z)*(g.x-c.x)+(a.x-c.x)*(g.z-c.z))/det;
      return {h:u*a.y+v*b.y+(1-u-v)*c.y,inside:u>=-.005&&v>=-.005&&u+v<=1.005};
    }
    // The two sides have opposite winding and opposite diagonals.
    const t=side>0?bary(a,b,c):bary(e,c,b),u=side>0?bary(a,c,e):bary(e,b,a);
    const y=t?.inside?t.h:u?.inside?u.h:g.y;
    return new THREE.Vector3(g.x,y+raised,g.z);
  }
  function groundMesh(p,uv,ix,mat){
    for(let i=0;i<ix.length;i+=3){
      const a=ix[i]*3,b=ix[i+1]*3,c=ix[i+2]*3;
      if((p[b+2]-p[a+2])*(p[c]-p[a])-(p[b]-p[a])*(p[c+2]-p[a+2])<0)[ix[i+1],ix[i+2]]=[ix[i+2],ix[i+1]];
    }
    const g=new THREE.BufferGeometry();g.setAttribute('position',new THREE.Float32BufferAttribute(p,3));g.setAttribute('uv',new THREE.Float32BufferAttribute(uv,2));g.setIndex(ix);g.computeVertexNormals();batch.add(g,mat,new THREE.Matrix4());g.dispose();
  }
  function surfaceRibbon(s0,s1,d0,d1,mat,step=.5,raised=0){
    const p=[],uv=[],ix=[],rows=Math.max(1,Math.ceil((s1-s0)/step));
    for(let i=0;i<=rows;i++){
      const s=lerp(s0,s1,i/rows);
      for(const d of [d0,d1]){const a=renderedGround(s,d,raised);p.push(a.x,a.y,a.z);uv.push(d===d0?0:1,i/rows);}
      if(i<rows){const a=i*2;ix.push(a,a+2,a+3,a,a+3,a+1);}
    }groundMesh(p,uv,ix,mat);
  }
  function crossPatch(s,side,d0,d1,w0,w1,mat,steps=10,raised=.028){
    const p=[],uv=[],ix=[];
    for(let i=0;i<=steps;i++){
      const t=i/steps,d=side*lerp(d0,d1,t),hw=lerp(w0,w1,t);
      for(const ss of [s-hw,s+hw]){const a=renderedGround(ss,d,raised);p.push(a.x,a.y,a.z);uv.push(ss===s-hw?0:1,t);}
      if(i<steps){const a=i*2;ix.push(a,a+2,a+3,a,a+3,a+1);}
    }groundMesh(p,uv,ix,mat);
  }

  function signTexture(kind,text){
    return canvasTexture(512,kind==='name'?160:512,(c,W,H)=>{
      c.clearRect(0,0,W,H);
      if(kind==='speed'){
        c.fillStyle='#f3f0e7';c.beginPath();c.arc(256,256,236,0,Math.PI*2);c.fill();c.strokeStyle='#a92228';c.lineWidth=43;c.beginPath();c.arc(256,256,215,0,Math.PI*2);c.stroke();
        c.fillStyle='#151719';c.textAlign='center';c.textBaseline='middle';c.font='bold 255px Arial';c.fillText(text,256,273);
      }else if(kind==='humps'){
        c.lineJoin='round';c.beginPath();c.moveTo(256,25);c.lineTo(489,451);c.lineTo(23,451);c.closePath();c.fillStyle='#f4f1e5';c.fill();c.strokeStyle='#aa2428';c.lineWidth=35;c.stroke();
        c.fillStyle='#1d1f1f';c.beginPath();c.moveTo(114,385);c.lineTo(114,365);c.bezierCurveTo(159,365,158,304,197,304);c.bezierCurveTo(235,304,234,365,256,365);c.bezierCurveTo(278,365,277,304,316,304);c.bezierCurveTo(356,304,357,365,400,365);c.lineTo(400,385);c.fill();
      }else{
        c.fillStyle='#e4e1d6';c.fillRect(0,0,W,H);c.strokeStyle='#3b3b35';c.lineWidth=12;c.strokeRect(7,7,W-14,H-14);c.textAlign='center';c.textBaseline='middle';c.fillStyle='#252a28';c.font='bold 58px Arial';c.fillText(text,W/2,H/2+4,W-44);
      }
    });
  }
  const speedTex=signTexture('speed','20'), humpTex=signTexture('humps',''), nameTex=signTexture('name',P.village.name);
  for(const [s,dir]of [[P.village.start,1],[P.village.end,-1]]){
    for(const side of [1,-1]){
      const g=w.groundAt(s,side*4.2),f={...R.frame(s)},yaw=Math.atan2(-f.tx*dir,-f.tz*dir);
      w.signPost(g.x,g.y,g.z,yaw,speedTex,.76,.76,1.55);
      if(side===dir)w.signPost(g.x+f.nx*side*.5,g.y,g.z+f.nz*side*.5,yaw,nameTex,1.65,.52,.82,true);
      // Return journey leaves the settlement into the open-road limit.
      w.signPost(g.x-f.tx*dir*.3,g.y,g.z-f.tz*dir*.3,yaw+Math.PI,E.tex.signNSL(),.60,.60,1.62);
    }
    w.vergeSign(s-25*dir,dir,humpTex,.86,.86,1.42,dir<0);
  }
  // Keep paint aged and embedded in the asphalt; no neon racing-game stripes.
  const paint=material('worn hump paint',0xcac8b8,{roughness:.95,polygonOffset:true,polygonOffsetFactor:-3,polygonOffsetUnits:-3,side:THREE.DoubleSide});
  const humpAsphalt=material('traffic calming asphalt',0x777574,{map:w.textures.asphalt.map,roughness:.95});
  for(const h of P.humps){
    surfaceRibbon(h.s-h.length/2,h.s+h.length/2,-3.05,3.05,humpAsphalt,.18,.002);
    for(const dir of [1,-1])for(const d of [1.50,-1.50]){
      const base=h.s-dir*(h.length/2-.2),tip=h.s-dir*.12;
      const pp=[road(base,d-.43,.019),road(base,d+.43,.019),road(tip,d,.019)];
      // Subdivide the triangle to follow the hump, rather than bridge above it.
      const pos=[],idx=[];
      for(let k=0;k<=12;k++){
        const t=k/12,s=lerp(base,tip,t),half=.43*(1-t);
        for(const dd of [d-half,d+half]){const p=road(s,dd,.022);pos.push(p.x,p.y,p.z);}
        if(k<12){const a=k*2;idx.push(a,a+2,a+3,a,a+3,a+1);}
      }
      const geo=new THREE.BufferGeometry();geo.setAttribute('position',new THREE.Float32BufferAttribute(pos,3));geo.setIndex(idx);geo.computeVertexNormals();batch.add(geo,paint,new THREE.Matrix4());geo.dispose();
    }
  }
  const buff=material('buff rumble strips',0xa6997d,{roughness:1});
  for(const s of P.strips)surfaceRibbon(s.s-s.length/2,s.s+s.length/2,-2.85,2.85,buff,.08,.003);

  // Tar-sealed repairs: irregular contours, aggregate speckle and feathered edges.
  const patchTex=canvasTexture(512,512,(c,W,H)=>{
    const r=E.rng(54);c.clearRect(0,0,W,H);
    c.beginPath();c.moveTo(32,23);c.lineTo(472,34);c.lineTo(485,472);c.lineTo(22,487);c.closePath();c.fillStyle='#282a29';c.fill();c.strokeStyle='#171b19';c.lineWidth=13;c.stroke();
    for(let i=0;i<15500;i++){let x=28+r()*450,y=30+r()*448,v=48+Math.floor(r()*42);c.fillStyle=`rgba(${v},${v+1},${v},${.15+r()*.4})`;c.fillRect(x,y,1+r()*2,1+r()*2);}
    c.strokeStyle='rgba(119,115,99,.3)';c.lineWidth=2;c.strokeRect(36,36,433,440);
  });
  const patchMat=material('surface repairs',0xb1b0a6,{map:patchTex,alphaTest:.2,transparent:false,side:THREE.DoubleSide,polygonOffset:true,polygonOffsetFactor:-2,polygonOffsetUnits:-2});
  for(const p of P.repairs)surfaceRibbon(p.s-p.length/2,p.s+p.length/2,p.d-p.width/2,p.d+p.width/2,patchMat,.45,.010);
  // Manhole castings are flush/sunken, with short tyre inputs rather than huge obstacles.
  const coverTex=canvasTexture(256,256,(c,W,H)=>{
    c.fillStyle='#313733';c.fillRect(0,0,W,H);c.strokeStyle='#161c19';c.lineWidth=16;c.strokeRect(8,8,W-16,H-16);
    for(let y=27;y<235;y+=20)for(let x=25;x<240;x+=26){c.fillStyle='#606460';c.fillRect(x,y,15,4);c.fillStyle='#1a201c';c.fillRect(x,y+5,15,3);}
    c.fillStyle='#121713';c.fillRect(58,116,24,10);c.fillRect(172,116,24,10);
  });
  const coverMat=material('inspection cover',0xb3b5ac,{map:coverTex,roughness:.65,metalness:.34,side:THREE.DoubleSide});
  for(const c of P.covers)surfaceRibbon(c.s-.33,c.s+.33,c.d-.325,c.d+.325,coverMat,.055,.014);
  const drainMap=canvasTexture(128,256,(c,W,H)=>{
    c.fillStyle='#50534e';c.fillRect(0,0,W,H);c.fillStyle='#232621';c.fillRect(8,8,W-16,H-16);
    for(let y=15;y<H-16;y+=21){c.fillStyle='#666860';c.fillRect(12,y,W-24,9);c.fillStyle='#94938a';c.fillRect(12,y,W-24,2);}
    c.fillStyle='#2b2f29';c.fillRect(W/2-4,10,8,H-20);
  });
  const drainMat=material('slotted drain gratings',0xa2a399,{map:drainMap,metalness:.25,roughness:.9,side:THREE.DoubleSide});
  for(let s=P.village.start+16;s<P.village.end-4;s+=26)for(const side of [1,-1]){
    surfaceRibbon(s-.32,s+.32,side*2.94-.20,side*2.94+.20,drainMat,.2,.014);
  }
  // Hairline tar snakes and road-edge seams are static world geometry.
  const tar=material('tar seams',0x383b35,{roughness:.65,side:THREE.DoubleSide,polygonOffset:true,polygonOffsetFactor:-2,polygonOffsetUnits:-2});
  for(let i=0;i<45;i++){
    const s=mod(166+i*49,R.length),d=(i%2?1:-1)*(1.5+rnd()*.9);
    for(let k=0;k<6;k++)surfaceRibbon(s+k*.65,s+(k+1)*.65,d+Math.sin(k*1.6+i)*.07-.016,d+Math.sin(k*1.6+i)*.07+.016,tar,.2,.009);
  }
  // Local irregular damage; no moving asphalt, weather particles or full-screen effects.
  const edgeMap=canvasTexture(256,512,(c,W,H)=>{
    const r=E.rng(141),im=c.createImageData(W,H);
    for(let y=0;y<H;y++)for(let x=0;x<W;x++){
      const u=x/W,v=y/H,border=.08+.15*E.noise2(y*.023,2),mask=smoothstep(border,border+.12,u)*(1-smoothstep(.78,.98,u))*smoothstep(0,.13,v)*(1-smoothstep(.84,1,v));
      const tone=86+r()*64,i=(y*W+x)*4;im.data[i]=tone+5;im.data[i+1]=tone+2;im.data[i+2]=tone-8;im.data[i+3]=mask*255;
    }c.putImageData(im,0,0);
  });
  const edgeWear=material('crumbled shoulder aggregate',0xb9b3a6,{map:edgeMap,alphaTest:.34,roughness:1,side:THREE.DoubleSide,polygonOffset:true,polygonOffsetFactor:-2,polygonOffsetUnits:-2});edgeWear.userData.noShadow=true;
  for(let k=0;k<112;k++){
    const ss=mod(23+k*21.7,R.length),side=k%2?1:-1;
    if(R.inVillage(ss,10)||R.clearance(ss,side)||R.inJunctionMouth(ss,side,18))continue;
    const rows=16,len=2+rnd()*4,p=[],uv=[],ix=[];
    for(let j=0;j<=rows;j++){
      const t=j/rows,sp=ss+len*(t-.5),wiggle=.035*Math.sin(t*16+k)+.02*Math.sin(t*35);
      for(let a=0;a<3;a++){
        const d=side*lerp(2.92+wiggle,3.39+wiggle,a/2),q=renderedGround(sp,d,.006);
        p.push(q.x,q.y,q.z);uv.push(a/2,t);
      }
      if(j<rows)for(let a=0;a<2;a++){const b=j*3+a;ix.push(b,b+3,b+4,b,b+4,b+1);}
    }groundMesh(p,uv,ix,edgeWear);
  }
  const holeTex=canvasTexture(256,256,(c,W,H)=>{
    const r=E.rng(621),im=c.createImageData(W,H);
    for(let y=0;y<H;y++)for(let x=0;x<W;x++){
      const a=(x-W/2)/(W/2),b=(y-H/2)/(H/2),q=Math.hypot(a,b),v=40+r()*21+smoothstep(.50,.92,q)*(20+r()*27),i=(y*W+x)*4;
      im.data[i]=v+3;im.data[i+1]=v+1;im.data[i+2]=v-5;im.data[i+3]=(1-smoothstep(.94,1,q))*255;
    }c.putImageData(im,0,0);
  });
  const holeMat=material('shallow pothole aggregate',0xbab7af,{map:holeTex,alphaTest:.22,roughness:1,side:THREE.DoubleSide,polygonOffset:true,polygonOffsetFactor:-3,polygonOffsetUnits:-3});holeMat.userData.noShadow=true;
  for(const h of P.potholes){
    const p=[],uv=[],ix=[],N=16;
    for(let y=0;y<=N;y++)for(let x=0;x<=N;x++){
      const a=(x/N-.5)*h.length,b=(y/N-.5)*h.width,sp=h.s+a,d=h.d+b,q=R.potholeRadius(h,sp,d),v=road(sp,d,.002);
      // Alpha boundary agrees with the actual scalloped depression, not a square overlay.
      p.push(v.x,v.y,v.z);const angle=Math.atan2(b/(h.width/2),a/(h.length/2));uv.push(.5+Math.cos(angle)*q*.5,.5+Math.sin(angle)*q*.5);
      if(x<N&&y<N){const i=y*(N+1)+x;ix.push(i,i+1,i+N+2,i,i+N+2,i+N+1);}
    }groundMesh(p,uv,ix,holeMat);
  }

  // Standing water in the deeper potholes: a mirror of the sky, edged by the aggregate.
  const puddleMat=material('puddle reflections',0x1e2327,{roughness:.05,metalness:.92,side:THREE.DoubleSide,polygonOffset:true,polygonOffsetFactor:-4,polygonOffsetUnits:-4});
  const puddleGeo=new THREE.CircleGeometry(1,22);puddleGeo.rotateX(-Math.PI/2);
  for(const h of P.potholes.filter((_,i)=>i%2===0)){
    const v=road(h.s,h.d,.003),f={...R.frame(h.s)};
    object(puddleGeo,puddleMat,v.x,v.y,v.z,h.width*.34,1,h.length*.36,f.heading);
  }

  // Under tree canopies the lane often stays slightly darker and damp for longer.
  const dampMat=material('shaded damp asphalt',0x5d605e,{map:w.textures.asphalt.map,roughness:.42,metalness:.03,side:THREE.DoubleSide,polygonOffset:true,polygonOffsetFactor:-1,polygonOffsetUnits:-1});
  for(const seg of [{a:650,b:706},{a:874,b:918},{a:1198,b:1248},{a:1652,b:1702},{a:1982,b:2030}]){
    for(let k=0;k<3;k++){
      const ss=lerp(seg.a,seg.b,(k+1)/4),span=5+rnd()*4,d=1.35+(k%2)*.45;
      surfaceRibbon(ss-span,ss+span,-d-.75,-d+.75,dampMat,.35,.011);
    }
  }
  // Village footways: a small upstand, continuous paving and drainage channels.
  for(const side of [1,-1])for(let s=P.village.start+4;s<P.village.end-4;s+=1.6){
    const f={...R.frame(s)},p=at(s,side*3.22,.016);
    const nearLot=P.lots.find(l=>l.side===side&&Math.abs(l.s-s)<4.2);
    const drop=nearLot?1-smoothstep(2.8,4.2,Math.abs(nearLot.s-s)):0;
    box(concrete,p.x,p.y-.05*drop,p.z,.22,.15-.05*drop,1.65,f.heading);
    const g=at(s,side*4.25,.025);box(material('footway',0x85867f),g.x,g.y-.023*drop,g.z,1.84,.115-.04*drop,1.65,f.heading);
  }

  function roofGeo(width,depth,eave,ridge){
    const p=[-width/2,eave,-depth/2, width/2,eave,-depth/2, width/2,ridge,0,-width/2,ridge,0,
      -width/2,ridge,0,width/2,ridge,0,width/2,eave,depth/2,-width/2,eave,depth/2];
    const g=new THREE.BufferGeometry();g.setAttribute('position',new THREE.Float32BufferAttribute(p,3));g.setAttribute('uv',new THREE.Float32BufferAttribute([0,0,1,0,1,1,0,1,0,0,1,0,1,1,0,1],2));g.setIndex([0,1,2,0,2,3,4,5,6,4,6,7]);const uv=g.attributes.uv;for(let i=0;i<uv.count;i++)uv.setXY(i,uv.getX(i)*width/3.2,uv.getY(i)*Math.hypot(depth/2,ridge-eave)/2.0);g.computeVertexNormals();return g;
  }
  const gardenLeafMap=canvasTexture(512,512,(c,W,H)=>{
    c.drawImage(w.textures.haw.map.image,0,0,W,H);const im=c.getImageData(0,0,W,H),d=im.data;
    for(let i=0;i<d.length;i+=4){if(!d[i+3])continue;d[i]=Math.min(150,d[i]*.95+34);d[i+1]=Math.min(162,d[i+1]*.95+40);d[i+2]=Math.min(110,d[i+2]*.95+22);}c.putImageData(im,0,0);
  });
  const plantMat=material('layered garden leaves',0xc9cbb7,{map:gardenLeafMap,alphaTest:.40,side:THREE.DoubleSide,roughness:1});
  const plantCore=new THREE.IcosahedronGeometry(1,1),plantCoreMat=material('garden shrub interiors',0xc7c8b1,{map:w.textures.hedge.map,roughness:1});E.addFoliageFill(plantCoreMat,.15);
  E.addFoliageFill(plantMat,.17);
  const plantGeo=new THREE.BufferGeometry(),plantP=[],plantN=[],plantUv=[],plantIx=[];
  for(let k=0;k<3;k++){
    const angle=k*Math.PI/3,cs=Math.cos(angle),sn=Math.sin(angle),base=plantP.length/3;
    for(const [x,y]of [[-.58,0],[.58,0],[.52,.97],[-.52,.97]]){plantP.push(x*cs,y,x*sn);plantN.push(cs*.35,.85,sn*.35);}
    plantUv.push(0,0,1,0,1,1,0,1);plantIx.push(base,base+1,base+2,base,base+2,base+3);
  }
  plantGeo.setAttribute('position',new THREE.Float32BufferAttribute(plantP,3));plantGeo.setAttribute('normal',new THREE.Float32BufferAttribute(plantN,3));plantGeo.setAttribute('uv',new THREE.Float32BufferAttribute(plantUv,2));plantGeo.setIndex(plantIx);
  let plantCount=0,capCount=0;
  function plantAt(x,y,z,size,parent=null){object(plantCore,plantCoreMat,x,y+size*.36,z,size*.38,size*.34,size*.36,0,parent);object(plantGeo,plantMat,x,y,z,size,size,size,(x*1.72+z*2.08)%6.28,parent);plantCount++;}
  const paneGeos=[];
  for(let k=0;k<4;k++){
    const g=new THREE.PlaneGeometry(1,1),uv=g.attributes.uv;
    for(let i=0;i<uv.count;i++)uv.setX(i,(k+.014+uv.getX(i)*.972)/4);paneGeos.push(g);
  }
  function glazing(x,y,z,width,height,yaw,variant,parent){object(paneGeos[variant%4],glass,x,y,z,width,height,1,yaw,parent);}
  const numberTex=canvasTexture(512,128,(c,W,H)=>{
    c.fillStyle='#3b443d';c.fillRect(0,0,W,H);c.fillStyle='#e8e2cc';c.textAlign='center';c.textBaseline='middle';c.font='bold 46px Georgia';
    for(let k=0;k<8;k++){c.strokeStyle='#aaa387';c.lineWidth=2;c.strokeRect(k*64+3,23,58,82);c.fillText(String(2+k*2),k*64+32,65);}
  });
  const numberMat=material('cottage number plates',0xffffff,{map:numberTex,roughness:.83});
  const numbers=[];
  for(let k=0;k<8;k++){const g=new THREE.PlaneGeometry(.23,.20);const uv=g.attributes.uv;for(let j=0;j<uv.count;j++)uv.setX(j,(k+uv.getX(j))/8);numbers.push(g);}
  const flowerMat=material('muted garden flowers',0x887385,{roughness:1}),flowerGeo=new THREE.IcosahedronGeometry(1,0);
  const pathMat=material('aged limestone paving',0xbeb7a4,{map:mineralMap,roughness:1});
  function cottageExtras(base,width,depth,h,variant,pub,wallmat){
    const front=depth/2;
    // A continuous path joins the doorstep to the existing pedestrian gate.
    box(pathMat,0,.028,front+1.55,.94,.052,2.40,0,base);
    for(let j=0;j<5;j++)box(material('paving joints',0x8c877b),0,.056,front+.55+j*.47,.95,.005,.012,0,base);
    object(numbers[(variant*2+(pub?1:0))%8],numberMat,.84,1.70,front+.028,1,1,1,0,base);
    // Tiny wall light has no point-light or shadow-map cost.
    box(dark,-.79,1.89,front+.13,.12,.24,.19,0,base);box(cream,-.79,1.89,front+.228,.077,.145,.012,0,base);
    // Quoin blocks, deliberately sparse rather than a completely repeated grid.
    for(const x of [-width/2+.16,width/2-.16])for(let j=0;j<Math.floor(h/.40);j++){
      box(trim,x,j*.40+.22,front+.016,j%2?.30:.43,.20,.062,0,base);
    }
    if(variant===1||pub){
      const canopy=roofGeo(1.8,1.28,2.28,2.69);const tr=new THREE.Matrix4().makeTranslation(0,0,front+.50);tr.premultiply(base);batch.add(canopy,slate,tr);canopy.dispose();
      beam(wood,[-.70,1.96,front+.06],[-.70,2.27,front+.98],.037,base);
      beam(wood,[.70,1.96,front+.06],[.70,2.27,front+.98],.037,base);
    }
    // Side garden boundaries and narrow beds respect the front gate opening.
    for(const side of [-1,1]){
      const x=side*(width/2-.1);
      for(let z=front+.65;z<front+2.9;z+=.73){box(wood,x,.44,z,.075,.86,.075,0,base);}
      for(const y of [.32,.68])box(wood,x,y,front+1.70,.048,.075,2.40,0,base);
      box(material('garden soil',0x565142,{map:mineralMap,roughness:1}),side*(width*.31),.025,front+1.30,1.26,.048,1.3,0,base);
      for(let k=0;k<3;k++){
        const px=side*width*.31+(k-1)*.34,pz=front+1.4;
        plantAt(px,.03,pz,.43,base);
        object(flowerGeo,flowerMat,px,.37+(k%2)*.06,pz,.061,.065,.061,0,base);
      }
    }
  }
  function gatewayDetails(s,side){
    const mark=material('gatepost lichen',0xa1a392,{map:mineralMap,roughness:1});
    for(const ds of [-2.08,2.08]){
      const g=at(s+ds,side*R.HEDGE_OFFSET),f={...R.frame(s)};
      box(mark,g.x,g.y+.10,g.z,.27,.20,.28,f.heading);
      plantAt(g.x+f.nx*side*.28,g.y-.025,g.z+f.nz*side*.28,.48);
    }
  }

  function cottage(s,side,width=8,depth=6,pub=false,render=false){
    const setback=side*(10.5+depth*.15),g=w.groundAt(s,setback),f={...R.frame(s)},yaw=Math.atan2(-f.nx*side,-f.nz*side);
    const base=new THREE.Matrix4().compose(new THREE.Vector3(g.x,g.y-.06,g.z),new THREE.Quaternion().setFromAxisAngle(UP,yaw),new THREE.Vector3(1,1,1));
    const h=pub?5.2:4.8+(s%3)*.09, front=depth/2, wallmat=render?renderMat:stone;
    const variant=Math.abs(Math.floor(s))%4;
    box(wallmat,0,h/2-.24,0,width,h+.48,depth,0,base);
    cottageExtras(base,width,depth,h,variant,pub,wallmat);
    const roof=roofGeo(width+.5,depth+.65,h,h+2.1);batch.add(roof,slate,base);roof.dispose();
    // Solid triangular gables close the volume under the slate slopes.
    const gg=new THREE.BufferGeometry();gg.setAttribute('position',new THREE.Float32BufferAttribute([-width/2,h,-depth/2,-width/2,h,depth/2,-width/2,h+2.1,0,width/2,h,-depth/2,width/2,h+2.1,0,width/2,h,depth/2],3));gg.setAttribute('uv',new THREE.Float32BufferAttribute([0,0,1,0,.5,.4,0,0,.5,.4,1,0],2));gg.computeVertexNormals();batch.add(gg,wallmat,base);gg.dispose();
    for(const x of [-width*.35,width*.35]){
      box(wallmat,x,h+1.68,0,.65,1.8,.7,0,base);box(trim,x,h+2.6,0,.8,.17,.84,0,base);
      object(poleGeo,brick,x-.16,h+2.91,0,.105,.48,.105,0,base);object(poleGeo,brick,x+.16,h+2.91,0,.105,.48,.105,0,base);
    }
    for(const floor of [0,1])for(const x of [-width*.31,width*.31]){
      const y=1.50+floor*2.28,ww=pub&&floor===0?1.52:.94,hh=1.24;
      box(trim,x,y,front+.01,ww+.23,hh+.21,.16,0,base);
      box(dark,x,y,front+.103,ww,hh,.06,0,base);
      glazing(x,y,front+.175,ww-.08,hh-.08,0,variant+floor,base);
      for(const xx of [x-ww/2,x,x+ww/2])box(white,xx,y,front+.174,.046,hh,.04,0,base);
      for(const yy of [y-hh/2,y,y+hh/2])box(white,x,yy,front+.18,ww,.042,.04,0,base);
      box(trim,x,y-hh/2-.095,front+.13,ww+.35,.12,.32,0,base);
    }
    // A pair of inset gable windows on each side avoids blank repeated end walls.
    for(const sideFace of [-1,1])for(const floor of [0,1]){
      const x=sideFace*(width/2+.018),y=1.52+floor*2.26,z=-.62;
      box(trim,x,y,z,.13,1.28,1.04,0,base);
      box(dark,x+sideFace*.07,y,z,.036,1.1,.86,0,base);
      glazing(x+sideFace*.116,y,z,.8,1.04,sideFace*Math.PI/2,variant+floor+1,base);
      for(const zz of [z-.43,z,z+.43])box(white,x+sideFace*.116,y,zz,.04,1.10,.045,0,base);
      for(const yy of [y-.55,y,y+.55])box(white,x+sideFace*.116,yy,z,.04,.045,.86,0,base);
      box(trim,x+sideFace*.09,y-.66,z,.26,.12,1.12,0,base);
    }
    const doorMat=material(pub?'pub green':'cottage door '+variant,pub?0x253e36:[0x3d514a,0x3d505c,0x624239,0xb9b5a7][variant],{roughness:.87});
    box(trim,0,1.1,front+.012,1.22,2.30,.20,0,base);box(doorMat,0,1.02,front+.13,1.0,2.05,.08,0,base);
    for(const yy of [.55,1.23])box(doorMat,0,yy,front+.18,.71,.49,.035,0,base);
    box(metal,.32,1.05,front+.205,.07,.035,.025,0,base);box(dark,0,.85,front+.20,.29,.035,.026,0,base);
    box(trim,0,.02,front+.42,1.7,.17,.72,0,base);
    // Gutters, drainpipes, ridge coping and a low stone boundary with a gate opening.
    for(const zz of [-front-.20,front+.20])box(dark,0,h-.07,zz,width+.46,.10,.11,0,base);
    for(const x of [-width/2+.13,width/2-.13])box(dark,x,h/2,front+.25,.075,h,.075,0,base);
    box(dark,0,h+2.13,0,width+.5,.08,.11,0,base);
    const gardenZ=front+2.7;
    for(const sx of [-1,1]){box(stone,sx*(width/4+.4),.45,gardenZ,width/2-.8,.9,.35,0,base);box(trim,sx*(width/4+.4),.94,gardenZ,width/2-.72,.13,.44,0,base);}
    for(const x of [-.63,.63])box(wood,x,.63,gardenZ,.11,1.25,.11,0,base);
    for(let x=-.48;x<.55;x+=.18)box(wood,x,.52,gardenZ,.09,.93,.065,0,base);
    for(const y of [.26,.75])box(wood,0,y,gardenZ+.015,1.15,.08,.08,0,base);
    // Planting in window boxes, with small irregular foliage volumes.
    for(const x of [-width*.31,width*.31]){
      box(wood,x,.78,front+.32,1.14,.21,.32,0,base);
      for(let k=0;k<4;k++)plantAt(x-.42+k*.28,.84,front+.34,.27,base);
    }
    if(pub){
      const tex=canvasTexture(768,160,(c,W,H)=>{c.fillStyle='#23362e';c.fillRect(0,0,W,H);c.strokeStyle='#b5a57b';c.lineWidth=8;c.strokeRect(8,8,W-16,H-16);c.fillStyle='#e7dec1';c.font='bold 60px Georgia';c.textAlign='center';c.textBaseline='middle';c.fillText('THE OLD MILL',W/2,H/2+3);});
      const mat=material('pub sign',0xffffff,{map:tex});object(new THREE.PlaneGeometry(4.0,.8),mat,0,3.0,front+.20,1,1,1,0,base);
    }
    return {base,g,f};
  }
  const buildings=[
    [345,-1,8.3,6.1,false,false],[351,1,7.8,6,false,false],[368,1,8.0,6.1,false,true],
    [378,-1,8.2,6.2,false,false],[391,-1,9.5,6.4,false,false],[401,1,7.7,6,false,false],
    [417,1,8.1,6.2,false,true],[425,-1,9.2,6.5,false,false],
    [463,-1,11.5,7,true,false],[469,1,8.4,6.1,false,true],[489,1,7.5,6,false,false],
    [493,-1,8.5,6.2,false,false],[518,1,8.3,6,false,false],[537,-1,8.9,6.5,false,false],
    [558,1,9.6,6.5,false,false],[570,-1,7.4,5.8,false,true],[580,1,7.8,6,false,false]
  ];
  buildings.forEach(args=>cottage(...args));

  // Driveways, frontage parking cues and wheelie bins help the village read as inhabited.
  const driveTex=canvasTexture(384,384,(c,W,H)=>{c.fillStyle='#8d877c';c.fillRect(0,0,W,H);for(let i=0;i<8500;i++){const v=118+Math.floor(rnd()*52);c.fillStyle=`rgba(${v},${v-3},${v-7},${.08+rnd()*.18})`;c.fillRect(rnd()*W,rnd()*H,1+rnd()*3,1+rnd()*3);}c.strokeStyle='rgba(88,79,68,.22)';c.lineWidth=5;for(let y=18;y<H;y+=32){c.beginPath();c.moveTo(0,y);c.lineTo(W,y+rnd()*9-4);c.stroke();}});
  const driveMat=material('driveway chippings',0xdfd9cf,{map:driveTex,roughness:.97,side:THREE.DoubleSide,polygonOffset:true,polygonOffsetFactor:-1,polygonOffsetUnits:-1});
  const tarmacLayby=material('frontage tarmac',0x72706b,{map:w.textures.asphalt.map,roughness:.96,side:THREE.DoubleSide,polygonOffset:true,polygonOffsetFactor:-1,polygonOffsetUnits:-1});
  const binGreen=material('wheelie bin green',0x2d4c3c,{roughness:.92}),binBlue=material('wheelie bin blue',0x365872,{roughness:.9});
  const shrubGeo=new THREE.IcosahedronGeometry(1,1),shrubMat=material('garden shrubbery',0x3e4a32,{roughness:1});
  // Cars sit in cleared spaces between buildings, not in their footprints.
  for(const [i,lot] of P.lots.entries()){
    crossPatch(lot.s,lot.side,3.32,12.8,2.85,3.15,i===2?tarmacLayby:driveMat,22,.064);
    const f={...R.frame(lot.s)};
    for(const end of [-1,1]){
      const a=at(lot.s+end*3.2,lot.side*5.58,.57);
      box(stone,a.x,a.y,a.z,.32,1.15,.32,f.heading);
      box(trim,a.x,a.y+.61,a.z,.42,.13,.42,f.heading);
      // Recessed wheelie bin, correctly scaled with lid, wheels and handles.
      const g=at(lot.s+end*2.35,lot.side*11.8),yaw=f.heading;
      const bm=end>0?binGreen:binBlue,base=new THREE.Matrix4().compose(g,new THREE.Quaternion().setFromAxisAngle(UP,yaw),new THREE.Vector3(1,1,1));
      box(bm,0,.54,0,.49,.91,.54,0,base);box(dark,0,1.01,.015,.54,.07,.61,0,base);
      for(const x of [-.26,.26]){object(flowerGeo,dark,x,.10,-.23,.085,.10,.065,0,base);box(dark,x*.76,1.02,-.25,.048,.035,.17,0,base);}
      plantAt(a.x+f.nx*lot.side*.4,a.y-.58,a.z+f.nz*lot.side*.4,.51);
    }
  }

  for(const a of buildings){
    const [s,side,width,depth]=a;
    for(const end of [-1,1])for(let k=0;k<5;k++){
      const p=at(s+end*(width/2+.9),side*(6.5+k*.65),.36);
      plantAt(p.x,p.y-.31,p.z,.72+rnd()*.24);
    }
  }
  // Authentic scale cues: a post box, litter bin, timetable, timber shelter and bench.
  {
    const s=451,d=6.15,g=w.groundAt(s,d),f={...R.frame(s)};
    object(poleGeo,red,g.x,g.y+.64,g.z,.22,1.25,.22);
    object(new THREE.SphereGeometry(1,14,8),red,g.x,g.y+1.29,g.z,.245,.11,.245);
    const base=new THREE.Matrix4().compose(at(s,d),new THREE.Quaternion().setFromAxisAngle(UP,Math.atan2(-f.nx,-f.nz)),new THREE.Vector3(1,1,1));
    box(dark,0,1.0,.224,.22,.04,.018,0,base);box(cream,0,.68,.227,.17,.24,.02,0,base);
    const b=at(456,6.3,.44);object(poleGeo,dark,b.x,b.y,b.z,.24,.88,.24);
    const sb=w.groundAt(440,7.0),sf={...R.frame(440)},syaw=Math.atan2(-sf.nx,-sf.nz);
    const sh=new THREE.Matrix4().compose(new THREE.Vector3(sb.x,sb.y,sb.z),new THREE.Quaternion().setFromAxisAngle(UP,syaw),new THREE.Vector3(1,1,1));
    for(const x of [-1.22,1.22])for(const z of [-.50,.55])box(wood,x,1.12,z,.11,2.24,.11,0,sh);
    box(wood,0,1.05,-.54,2.5,1.9,.10,0,sh);box(slate,0,2.3,0,2.86,.18,1.55,0,sh);
    for(let y=.23;y<2.0;y+=.22)box(material('shelter boards',0x6a604e),0,y,-.604,2.48,.028,.026,0,sh);
    box(wood,0,.5,-.17,2.13,.10,.42,0,sh);box(wood,0,.87,-.44,2.1,.3,.06,0,sh);
    for(const x of [-.85,.85])box(dark,x,.24,-.13,.065,.5,.08,0,sh);
    const tt=canvasTexture(128,256,(c,W,H)=>{c.fillStyle='#ebe8dc';c.fillRect(0,0,W,H);c.fillStyle='#29594b';c.fillRect(0,0,W,42);c.fillStyle='#fff';c.font='bold 16px Arial';c.fillText('DALEBECK',10,27);c.fillStyle='#66695e';for(let y=58;y<240;y+=15){c.fillRect(10,y,25,3);c.fillRect(46,y,70,3);}});
    object(new THREE.PlaneGeometry(.32,.64),material('timetable',0xffffff,{map:tt}),.69,1.4,-.478,1,1,1,0,sh);
    const busTex=canvasTexture(256,384,(c,W,H)=>{c.fillStyle='#e7dda3';c.fillRect(0,0,W,H);c.fillStyle='#304638';c.fillRect(44,45,168,117);c.fillStyle='#f4efd9';c.fillRect(57,57,142,50);c.fillStyle='#304638';c.beginPath();c.arc(72,169,19,0,7);c.arc(185,169,19,0,7);c.fill();c.font='bold 35px Arial';c.textAlign='center';c.fillText('BUS STOP',W/2,242);c.font='22px Arial';c.fillText('DALEBECK',W/2,292);});
    w.vergeSign(436,1,busTex,.35,.53,2.04,false);
  }
  // Small lived-in village furniture: bench, noticeboard, grit bin and fingerpost.
  {
    const grit=material('grit bin',0xc9a53d,{roughness:.82}),greenBin=material('green metal bin',0x365240,{roughness:.9}),signWhite=material('fingerpost white',0xe7e3d7,{roughness:.8});
    const sb=w.groundAt(431,6.2),sf={...R.frame(431)},syaw=Math.atan2(-sf.nx,-sf.nz);
    const bench=new THREE.Matrix4().compose(new THREE.Vector3(sb.x,sb.y,sb.z),new THREE.Quaternion().setFromAxisAngle(UP,syaw),new THREE.Vector3(1,1,1));
    for(const x of [-.62,.62])box(dark,x,.22,0,.07,.44,.07,0,bench);
    for(const y of [.43,.58])box(wood,0,y,0,1.42,.07,.24,0,bench);
    box(wood,0,.81,-.08,1.38,.07,.18,.15,bench);
    const nb=w.groundAt(453,-6.6),nf={...R.frame(453)},nyaw=Math.atan2(nf.nx,nf.nz);
    const board=new THREE.Matrix4().compose(new THREE.Vector3(nb.x,nb.y,nb.z),new THREE.Quaternion().setFromAxisAngle(UP,nyaw),new THREE.Vector3(1,1,1));
    for(const x of [-.45,.45])box(wood,x,1.06,0,.09,2.12,.09,0,board);
    box(wood,0,1.92,0,1.24,1.04,.09,0,board);
    const noteTex=canvasTexture(320,220,(c,W,H)=>{c.fillStyle='#efe9d7';c.fillRect(0,0,W,H);c.fillStyle='#a89a71';c.fillRect(0,0,W,32);c.fillStyle='#314233';c.font='bold 18px Arial';c.fillText('PARISH NOTICEBOARD',14,22);for(let y=52;y<198;y+=28){c.fillStyle='rgba(82,82,72,.85)';c.fillRect(18,y,W-36,3);}c.fillStyle='#6b5d47';c.fillRect(28,132,90,46);});
    object(new THREE.PlaneGeometry(1.06,.72),material('noticeboard poster',0xffffff,{map:noteTex}),0,1.92,.055,1,1,1,0,board);
    const gb=at(459,-6.35,.44);box(greenBin,gb.x,gb.y,gb.z,.28,.76,.28,R.frame(459).heading);box(dark,gb.x,gb.y+.39,gb.z,.30,.05,.30,R.frame(459).heading);
    const gr=at(576,6.45,.36);box(grit,gr.x,gr.y,gr.z,.86,.56,.46,R.frame(576).heading);
    const fp=w.groundAt(584,-5.8),ff={...R.frame(584)},fyaw=Math.atan2(ff.nx,ff.nz);
    const finger=new THREE.Matrix4().compose(new THREE.Vector3(fp.x,fp.y,fp.z),new THREE.Quaternion().setFromAxisAngle(UP,fyaw),new THREE.Vector3(1,1,1));
    box(dark,0,1.12,0,.09,2.24,.09,0,finger);
    box(signWhite,.52,1.55,.02,1.04,.17,.11,.08,finger); box(signWhite,-.52,1.92,.02,1.04,.17,.11,-.08,finger);
    box(dark,.52,1.55,.08,.77,.03,.03,.08,finger); box(dark,-.52,1.92,.08,.74,.03,.03,-.08,finger);
  }

  // A K6 telephone box by the bus shelter: cast-iron red, domed roof, glazed on three sides.
  {
    const kb=w.groundAt(447,-6.9),kf={...R.frame(447)},kyaw=Math.atan2(kf.nx,kf.nz);
    const base=new THREE.Matrix4().compose(new THREE.Vector3(kb.x,kb.y,kb.z),new THREE.Quaternion().setFromAxisAngle(UP,kyaw),new THREE.Vector3(1,1,1));
    const kiosk=material('kiosk red',0xa3141c,{roughness:.42,metalness:.2});
    box(kiosk,0,.16,0,1.0,.32,1.0,0,base);                                   // plinth
    for(const [x,z] of [[-.44,-.44],[.44,-.44],[-.44,.44],[.44,.44]])box(kiosk,x,1.3,z,.12,2.3,.12,0,base);
    box(kiosk,0,1.3,-.45,1.0,2.3,.08,0,base);                                 // solid back
    for(const face of [[0,.45,0],[-.45,0,Math.PI/2],[.45,0,Math.PI/2]]){
      const [x,z,yaw]=face;
      for(let r=0;r<6;r++)glazing(x,.62+r*.31,z,.74,.27,yaw,r,base);
      for(let r=0;r<7;r++)box(kiosk,x,.47+r*.31,z,yaw?.08:.8,.035,yaw?.8:.08,0,base);
      for(const off of [-.25,.25])box(kiosk,yaw?x:x+off,1.4,yaw?z+off:z,yaw?.08:.05,1.9,yaw?.05:.08,0,base);
    }
    box(kiosk,0,2.53,0,1.0,.16,1.0,0,base);
    for(const x of [-.45,0,.45])box(cream,x,2.53,x?0:.51,x?.02:.8,.1,x?.9:.02,0,base);   // TELEPHONE strips
    box(kiosk,0,2.68,0,.86,.14,.86,0,base);box(kiosk,0,2.8,0,.6,.12,.6,0,base);box(kiosk,0,2.9,0,.3,.1,.3,0,base);
  }
  // Farmsteads out in the fields, the way the Dales are actually dotted with them.
  for(const [s,side,d,rot] of [[700,1,96,.4],[1560,-1,112,-.7],[2000,1,84,1.2]]){
    const a=w.groundAt(s,side*d),f={...R.frame(s)};
    const fb=new THREE.Matrix4().compose(new THREE.Vector3(a.x,a.y-.2,a.z),new THREE.Quaternion().setFromAxisAngle(UP,f.heading+rot),new THREE.Vector3(1,1,1));
    box(stone,0,2.4,0,13,4.8,7.5,0,fb);const r1=roofGeo(13.6,8.2,4.8,7.1);batch.add(r1,slate,fb);r1.dispose();
    for(const x of [-4.2,4.2]){box(stone,x,7.4,0,.7,1.3,.8,0,fb);}
    box(stone,10.5,1.7,-1,8,3.4,7,0,fb);const r2=roofGeo(8.5,7.6,3.4,5.0);const t2=new THREE.Matrix4().makeTranslation(10.5,0,-1);t2.premultiply(fb);batch.add(r2,slate,t2);r2.dispose();
    box(material('farmyard',0x7d766a,{map:mineralMap,roughness:1}),4,.03,7,22,.06,9,0,fb);
    for(let k=0;k<12;k++){const x=-9+k*2.1;box(stone,x,.55,11.5,2.1,1.1,.5,0,fb);}
  }

  // Working farm entrances align with real gaps in the boundary, not through a hedge.
  const mudMat=material('muddy gateway',0x635545,{map:patchTex,roughness:1,side:THREE.DoubleSide,polygonOffset:true,polygonOffsetFactor:-1,polygonOffsetUnits:-1});
  const rutMat=material('wet mud rut',0x4a4033,{roughness:1,side:THREE.DoubleSide,polygonOffset:true,polygonOffsetFactor:-1,polygonOffsetUnits:-1});
  for(const gate of P.gates){
    const s=gate.s,side=gate.side,f={...R.frame(s)},g=w.groundAt(s,side*R.HEDGE_OFFSET);
    const base=new THREE.Matrix4().compose(new THREE.Vector3(g.x,g.y,g.z),new THREE.Quaternion().setFromAxisAngle(UP,f.heading),new THREE.Vector3(1,1,1));
    // Gate plane follows the road tangent, so local z spans the entrance.
    for(const z of [-2.05,2.05])box(wood,0,.7,z,.18,1.50,.18,0,base);
    for(const y of [.27,.48,.69,.90,1.11])beam(metal,[0,y,-1.92],[0,y,1.92],.023,base);
    for(const z of [-1.92,0,1.92])beam(metal,[0,.23,z],[0,1.16,z],.025,base);
    beam(metal,[0,.26,-1.92],[0,1.14,1.92],.020,base);
    // Ground-following gravel access, continuing into the field behind the closed gate.

    const pp=[],uv=[],idx=[];
    for(let k=0;k<=18;k++){
      const d=side*(3.06+k*1.0),hw=k<4?2.3:1.7;
      for(const ss of [s-hw,s+hw]){const a=at(ss,d,.027);pp.push(a.x,a.y,a.z);uv.push(ss===s-hw?0:1,k/4);}
      if(k<18){const j=k*2;idx.push(j,j+2,j+3,j,j+3,j+1);}
    }
    const geo=new THREE.BufferGeometry();geo.setAttribute('position',new THREE.Float32BufferAttribute(pp,3));geo.setAttribute('uv',new THREE.Float32BufferAttribute(uv,2));geo.setIndex(idx);geo.computeVertexNormals();
    gravel.side=THREE.DoubleSide;batch.add(geo,gravel,new THREE.Matrix4());geo.dispose();
    for(const shift of [-.68,.68])crossPatch(s+shift,side,3.7,18.8,.20,.26,rutMat,24,.052);
    gatewayDetails(s,side);
  }
  // Hay bales and field barns set behind boundaries, never on the carriageway.
  const hay=material('hay',0xa29558,{map:w.textures.grass.map,roughness:1});
  const baleGeo=new THREE.CylinderGeometry(.77,.77,1.15,20);baleGeo.rotateZ(Math.PI/2);
  for(const [s,side]of [[840,-1],[1710,1],[2152,-1]]){
    for(let k=0;k<9;k++){const a=at(s+(k%3)*3.1,side*(22+Math.floor(k/3)*3),.76);object(baleGeo,hay,a.x,a.y,a.z,1,1,1,R.frame(s).heading);}
    const a=w.groundAt(s+22,side*30),f={...R.frame(s+22)},base=new THREE.Matrix4().compose(new THREE.Vector3(a.x,a.y-.1,a.z),new THREE.Quaternion().setFromAxisAngle(UP,f.heading),new THREE.Vector3(1,1,1));
    box(stone,0,1.7,0,12,3.4,7,0,base);const rg=roofGeo(12.5,7.6,3.4,5.1);batch.add(rg,slate,base);rg.dispose();box(material('barn doors',0x464e42),0,1.45,3.53,3.0,2.9,.09,0,base);
  }
  // Crop texture is shaded into the existing terrain: no duplicate ground, floating strips or new draw calls.
  const fieldPlans=[{s:846,side:-1,d:45,wide:18,long:54},{s:1144,side:1,d:48,wide:18,long:57},{s:1485,side:-1,d:47,wide:19,long:49}];
  const fieldDefs=fieldPlans.map((p,i)=>{const f={...R.frame(p.s)};return {x:f.x+f.nx*p.side*p.d,z:f.z+f.nz*p.side*p.d,tx:f.tx,tz:f.tz,nx:f.nx,nz:f.nz,...p};});
  const oldGrass=w.materials.grassMat.onBeforeCompile;
  w.materials.grassMat.onBeforeCompile=(shader,r)=>{
    if(oldGrass)oldGrass(shader,r);
    shader.vertexShader=shader.vertexShader.replace('#include <common>','#include <common>\nvarying vec2 vFieldWorld;').replace('#include <project_vertex>','#include <project_vertex>\nvFieldWorld=(modelMatrix*vec4(transformed,1.0)).xz;');
    shader.fragmentShader=shader.fragmentShader.replace('#include <common>','#include <common>\nvarying vec2 vFieldWorld;');
    const code=fieldDefs.map((f,i)=>`{
      vec2 dp=vFieldWorld-vec2(${f.x.toFixed(3)},${f.z.toFixed(3)});
      float across=dot(dp,vec2(${f.nx.toFixed(6)},${f.nz.toFixed(6)})),along=dot(dp,vec2(${f.tx.toFixed(6)},${f.tz.toFixed(6)}));
      float mask=(1.0-smoothstep(${(f.wide-2).toFixed(1)},${f.wide.toFixed(1)},abs(across)))*(1.0-smoothstep(${(f.long-4).toFixed(1)},${f.long.toFixed(1)},abs(along)));
      float aa=max(.06,fwidth(across)*1.4);
      float rows=.98+.035*cos(across*13.0)*exp(-aa*6.0);
      float tram=1.0-smoothstep(.13,.13+aa,min(abs(across-5.1),abs(across-6.45)));
      vec3 tint=vec3(${i===1?'1.15,1.04,.84':i===2?'1.06,1.03,.91':'.97,1.035,.90'});
      diffuseColor.rgb*=mix(vec3(1.0),tint*rows*(1.0-tram*.23),mask);
    }`).join('\n');
    shader.fragmentShader=shader.fragmentShader.replace('#include <map_fragment>','#include <map_fragment>\n'+code);
  };
  E.tagShader(w.materials.grassMat,'terrain-crops-v3');w.materials.grassMat.needsUpdate=true;

  // White verge delineators occur in a few runs rather than ringing the entire road.
  for(const [a,b]of [[735,817],[1295,1375],[1825,1885]])for(let s=a;s<b;s+=17)for(const side of [1,-1]){
    if(R.inJunctionMouth(s,side,14))continue;
    const p=at(s,side*3.65,.48),f={...R.frame(s)};
    box(cream,p.x,p.y,p.z,.105,.94,.15,f.heading);
    const q=at(s,side*3.65,.70);box(dark,q.x,q.y,q.z,.11,.21,.157,f.heading);
    const r=at(s-.085,side*3.65,.71);box(side===1?material('red reflector',0x9c3227):cream,r.x,r.y,r.z,.065,.07,.012,f.heading);
  }
  // Continuous wires meet their posts. Entrances are excluded from each run.
  for(const seg of [{a:728,b:842,side:-1,d:13.4},{a:1088,b:1190,side:1,d:14.2},{a:1468,b:1530,side:-1,d:15}]){
    let last=null;
    for(let ss=seg.a;ss<=seg.b;ss+=5.5){
      if(P.gates.some(g=>g.side===seg.side&&Math.abs(g.s-ss)<5)||R.inJunctionMouth(ss,seg.side,20)){last=null;continue;}
      const p=at(ss,seg.side*seg.d),f={...R.frame(ss)};box(wood,p.x,p.y+.56,p.z,.11,1.12,.11,f.heading);
      if(last)for(const h of [.36,.69,1.02])beam(metal,[last.x,last.y+h,last.z],[p.x,p.y+h,p.z],.006);
      last=p;
    }
  }
  // Few simple low-cost ferns break up uniform verge grass at the woodland edge.
  const fernTex=canvasTexture(512,512,(c,W,H)=>{
    const r=E.rng(947);c.clearRect(0,0,W,H);
    for(let k=0;k<10;k++){
      const angle=-2.92+k*.295+(r()-.5)*.12,len=170+r()*100,ox=256+(r()-.5)*26,oy=505;
      const dx=Math.cos(angle),dy=Math.sin(angle);c.strokeStyle='#565c39';c.lineWidth=2;c.beginPath();c.moveTo(ox,oy);c.quadraticCurveTo(ox+dx*len*.5,oy+dy*len*.82,ox+dx*len,oy+dy*len);c.stroke();
      for(let j=2;j<19;j++){
        const t=j/20,cx=ox+dx*len*t,cy=oy+dy*len*(1.64*t-.64*t*t),size=(1-t)*25+2;
        for(const side of [-1,1]){
          const px=-dy*side,py=dx*side;c.fillStyle=`rgb(${59+r()*25},${79+r()*30},${38+r()*15})`;
          c.beginPath();c.moveTo(cx-dx*3,cy-dy*3);c.quadraticCurveTo(cx+px*size*.45-dx*size*.3,cy+py*size*.45-dy*size*.3,cx+px*size+dx*4,cy+py*size+dy*4);c.quadraticCurveTo(cx+px*size*.5+dx*size*.3,cy+py*size*.5+dy*size*.3,cx+dx*3,cy+dy*3);c.fill();
        }
      }
    }
  });
  const fernMat=material('fern understorey',0xc0c3ad,{map:fernTex,alphaTest:.40,side:THREE.DoubleSide,roughness:1});E.addFoliageFill(fernMat,.13);
  const fernGeo=new THREE.PlaneGeometry(1.05,.77);fernGeo.translate(0,.385,0);
  const stoneCap=new THREE.IcosahedronGeometry(1,1),capMat=material('limestone wall coping',0x8b897b,{map:mineralMap,roughness:1});
  const flintMat=material('verge flints',0x898577,{map:mineralMap,roughness:1});
  for(let ss=10;ss<R.length;ss+=.65){
    for(const side of [1,-1]){
      if(R.clearance(ss,side)||R.inVillage(ss,12)||R.inJunctionMouth(ss,side,14))continue;
      const boundary=R.boundaryAt(ss,side);
      if(boundary.type==='wall'){
        const p=at(ss,side*R.HEDGE_OFFSET),h=boundary.height*(.94+E.noise2(ss/5,.7)*.12);
        object(stoneCap,capMat,p.x,p.y+h+.055,p.z,.29,.075+rnd()*.075,.29,R.frame(ss).heading+(rnd()-.5)*.18);capCount++;
      }
      if(R.woodland(ss)&&rnd()<.20){
        const p=renderedGround(ss,side*(3.75+rnd()*.66),-.018),size=.69+rnd()*.57,heading=rnd()*Math.PI;
        for(const yaw of [heading,heading+Math.PI*.5])object(fernGeo,fernMat,p.x,p.y,p.z,size,size,size,yaw);
        plantCount++;
      }
    }
  }
  // Sparse stones at the base of boundaries, never placed in the rideable lane.
  for(let ss=17;ss<R.length;ss+=7.1){
    const side=rnd()<.5?1:-1;if(R.clearance(ss,side)||R.inVillage(ss,12)||R.inJunctionMouth(ss,side,20))continue;
    const p=renderedGround(ss,side*(4.4+rnd()*.25),.027);object(stoneCap,flintMat,p.x,p.y,p.z,.05+rnd()*.12,.028+rnd()*.05,.06+rnd()*.11,rnd()*6.28);
  }
  // Chevron direction and placement are driven by the actual bend, not arbitrary offsets.
  let chevronCount=0;
  const arrowMaps={};
  function arrowTexture(dir){return arrowMaps[dir]||(arrowMaps[dir]=canvasTexture(512,192,(c,W,H)=>{
    c.fillStyle='#deded1';c.fillRect(0,0,W,H);c.strokeStyle='#30342f';c.lineWidth=7;c.strokeRect(4,4,W-8,H-8);c.fillStyle='#262a25';
    for(let k=0;k<3;k++){const cx=100+k*151;const path=[[42,33],[-18,96],[42,159],[-9,159],[-69,96],[-9,33]];c.beginPath();path.forEach(([x,y],i)=>i?c.lineTo(cx+x*dir,y):c.moveTo(cx+x*dir,y));c.closePath();c.fill();}
  }));}
  for(const bend of R.BENDS.filter(b=>b.radius<110).slice(0,4)){
    const ss=bend.apex*R.SAMPLE,side=-bend.dir;
    if(R.inVillage(ss,20)||R.inJunctionMouth(ss,side,23))continue;
    const p=w.groundAt(ss,side*4.05),viewer=R.point(ss-28,1.5,0,new THREE.Vector3()),yaw=Math.atan2(viewer.x-p.x,viewer.z-p.z);
    w.signPost(p.x,p.y,p.z,yaw,arrowTexture(bend.dir),1.65,.62,1.04,true);chevronCount++;
  }

  const added=batch.finish();
  // Dense roadside objects are spatially instanced, not rendered as a whole-loop
  // batch. Use the same geometry/material/depth shader, preserving wind and shadows.
  const candidates=[];
  scene.traverse(o=>{if(o.isInstancedMesh&&o.count>=64&&!o.userData.partitioned)candidates.push(o);});
  const cullables=[];
  for(const old of candidates){
    const bins=new Map(),mat=new THREE.Matrix4(),col=new THREE.Color();
    for(let i=0;i<old.count;i++){
      old.getMatrixAt(i,mat);if(mat.elements[0]===0&&mat.elements[5]===0&&mat.elements[10]===0)continue;
      const key=`${Math.floor(mat.elements[12]/96)},${Math.floor(mat.elements[14]/96)}`;
      if(!bins.has(key))bins.set(key,[]);bins.get(key).push(i);
    }
    const isGrass=old.geometry===w.grassGeometry||old.name==='verge grass';
    const isLeaf=old.name.includes('tree'),isSmall=old.geometry.attributes.position.count<=8;
    for(const indices of bins.values()){
      const im=new THREE.InstancedMesh(old.geometry,old.material,indices.length);
      im.name=old.name||'partitioned scenery';im.castShadow=old.castShadow;im.receiveShadow=old.receiveShadow;
      im.customDepthMaterial=old.customDepthMaterial;im.frustumCulled=true;im.userData.partitioned=true;
      indices.forEach((i,j)=>{old.getMatrixAt(i,mat);im.setMatrixAt(j,mat);if(old.instanceColor){old.getColorAt(i,col);im.setColorAt(j,col);}});
      im.computeBoundingSphere();im.computeBoundingBox();im.userData.detailDistance=isGrass?(quality.coarse?65:90):isSmall?(quality.coarse?145:190):isLeaf?(quality.coarse?430:560):470;
      im.userData.originalShadow=im.castShadow;
      old.parent.add(im);cullables.push(im);
    }
    old.parent.remove(old);old.dispose();
  }
  for(const m of added){m.userData.originalShadow=m.castShadow;cullables.push(m);}
  // Tiny, infrequent distant birds give the landscape some life without heavy models.
  const birdGeo=new THREE.BufferGeometry();birdGeo.setAttribute('position',new THREE.Float32BufferAttribute([-.6,0,0,-.13,.09,.05,0,0,0,0,0,0,.13,.09,.05,.6,0,0],3));birdGeo.computeVertexNormals();
  const birds=[];const birdMat=new THREE.MeshBasicMaterial({color:0x30362f,side:THREE.DoubleSide,fog:true});
  for(let k=0;k<7;k++){const b=new THREE.Mesh(birdGeo,birdMat);scene.add(b);birds.push(b);}
  let lastCull=-1;
  const oldUpdate=w.update;
  w.update=function(time,pos,forward){
    oldUpdate(time,pos,forward);
    if(time-lastCull>.16){
      for(const o of cullables){
        const b=o.isInstancedMesh?o.boundingSphere:o.geometry.boundingSphere;
        if(!b)continue;const dx=b.center.x-pos.x,dz=b.center.z-pos.z,d=Math.hypot(dx,dz)-b.radius;
        o.visible=d<o.userData.detailDistance;
        o.castShadow=o.userData.originalShadow&&d<(quality.coarse?95:125);
      }
      lastCull=time;
    }
    birds.forEach((b,k)=>{const ss=1050+k*9+Math.sin(time*.025)*45,f={...R.frame(ss)};b.position.set(f.x+f.nx*(60+k*2)+Math.sin(time*.14+k)*12,f.y+25+k*.9+Math.sin(time*.6+k)*.6,f.z+f.nz*(60+k*2)+Math.cos(time*.14+k)*12);b.rotation.set(0,time*.14+k*.18,Math.sin(time*2.4+k)*.16);});
  };
  w.detailStats={buildings:buildings.length,humps:P.humps.length,rumbleStrips:P.strips.length,repairs:P.repairs.length,covers:P.covers.length,gates:P.gates.length,batchedParts:batch.parts,detailMeshes:added.length,spatialBatches:cullables.length,chevrons:chevronCount,fenceRuns:3,dampZones:5};
  w.materials.detail=materials;w.sceneDetails={potholes:P.potholes.length,parkingBays:P.lots.length,plants:plantCount,wallCaps:capCount,fieldMaterials:3};return w;
};
