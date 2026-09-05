import * as THREE from 'three';
const E = window.EVO, R = E.route;
const {clamp, mod, smoothstep} = E;
const L = R.length;
const village = {name:'DALEBECK', start:330, end:595, limit:20};
const gates = [{s:236,side:1},{s:811,side:-1},{s:963,side:1},{s:1390,side:-1},{s:1700,side:1},{s:2128,side:-1}];
const humps = [
  {s:372,length:3.8,height:0.075,type:'hump'},
  {s:448,length:7.0,height:0.075,type:'table'},
  {s:530,length:3.8,height:0.075,type:'hump'}
];
const strips=[];
for(const s0 of [301,610]) for(let i=0;i<6;i++) strips.push({s:s0+i*1.35,length:0.34,height:0.004,type:'strip'});
const rnd=E.rng(9031), repairs=[];
for(let i=0;i<58;i++) {
  const s=mod(143+i*39+rnd()*15,L);
  if(humps.some(h=>Math.abs(s-h.s)<9)) continue;
  repairs.push({s,d:(rnd()<0.5?1:-1)*(0.6+rnd()*1.75),length:0.9+rnd()*4.3,width:0.4+rnd()*0.85,seed:i+80});
}
const covers=[{s:357,d:1.80},{s:403,d:-1.40},{s:468,d:1.05},{s:558,d:-1.65},{s:873,d:1.8},{s:1890,d:-1.8}];
const potholes = [
  {s:214,d:2.42,length:1.05,width:.64,height:-.030,seed:13},
  {s:844,d:2.42,length:1.24,width:.68,height:-.034,seed:27},
  {s:1168,d:-2.42,length:.92,width:.60,height:-.025,seed:38},
  {s:1458,d:1.80,length:1.14,width:.70,height:-.028,seed:42},
  {s:1842,d:-2.42,length:1.38,width:.72,height:-.035,seed:55},
  {s:2090,d:2.42,length:.96,width:.62,height:-.028,seed:67}
].map(p=>({...p,type:'pothole'}));
const lots = [
  {s:361,side:-1,d:8.65,kind:'hatch',paint:0xd2d1c9,dir:1},
  {s:384,side:1,d:8.70,kind:'suv',paint:0x324d65,dir:-1},
  {s:442,side:-1,d:8.80,kind:'van',paint:0xe1ded3,dir:1},
  {s:504,side:1,d:8.65,kind:'hatch',paint:0x883839,dir:-1},
  {s:552,side:-1,d:8.65,kind:'hatch',paint:0x777d7a,dir:1}
];
const ds=(a,b)=>mod(a-b+L/2,L)-L/2;
const potholeRadius=(p,s,d)=>{
  const a=ds(s,p.s)/(p.length*.5),b=(d-p.d)/(p.width*.5);
  const angle=Math.atan2(b,a),edge=.91+.045*Math.sin(angle*5+p.seed)+.04*Math.cos(angle*7-p.seed);
  return Math.hypot(a,b)/edge;
};
R.potholeRadius=potholeRadius;
const inVillage=(s,margin=0)=>{s=mod(s,L);return s>=village.start-margin&&s<=village.end+margin;};
const woodland=(s)=>{s=mod(s,L);return (s>95&&s<310)||(s>995&&s<1260)||(s>1940&&s<2075);};
const clearance=(s,side)=>inVillage(s,8)||gates.some(g=>g.side===side&&Math.abs(ds(s,g.s))<3.15);

// Small longitudinal bins avoid scanning every feature for both wheels each physics step.
const binSize=16, binCount=Math.ceil(L/binSize), bins=Array.from({length:binCount},()=>[]);
const surfaces=[...humps,...strips,...potholes,...covers.map(c=>({...c,type:'cover',length:0.66,width:0.65,height:-0.012}))];
for(const f of surfaces){
  const keys=new Set();
  for(let s=f.s-f.length/2-1;s<=f.s+f.length/2+binSize+1;s+=binSize)keys.add(Math.floor(mod(s,L)/binSize));
  for(const k of keys)bins[k].push(f);
}
function surfaceAt(s,d){
  if(Math.abs(d)>3.1)return 0;
  let h=0;
  for(const f of bins[Math.floor(mod(s,L)/binSize)]){
    const t=ds(s,f.s), a=Math.abs(t), half=f.length/2;
    if(a>=half)continue;
    if(f.type==='pothole'){
      const q=potholeRadius(f,s,d);if(q<1)h+=f.height*(1-smoothstep(.32,1,q));
    }else if(f.type==='cover'){
      const q=Math.abs(d-f.d)/(f.width/2); if(q>=1)continue;
      h+=f.height*(1-smoothstep(0.62,1,q))*(1-smoothstep(0.22,half,a));
    }else{
      const lateral=1-smoothstep(2.87,3.08,Math.abs(d));
      const shape=f.type==='table' ? 1-smoothstep(half-1.2,half,a) : 0.5+0.5*Math.cos(Math.PI*t/half);
      h+=f.height*shape*lateral;
    }
  }
  return h;
}
function roughnessAt(s,d){
  let q=woodland(s)?0.32:inVillage(s)?0.11:0.18;
  const ss=mod(s,L);
  // Texture character is spatial, not tied to frame rate or elapsed wall-clock time.
  q+=0.17*E.noise2(ss*0.11,d*1.3+7);
  for(const f of bins[Math.floor(ss/binSize)]){
    if(Math.abs(ds(ss,f.s))>=f.length/2)continue;
    if(f.type==='strip')q+=0.9;
    if(f.type==='pothole'&&potholeRadius(f,s,d)<1)q+=.72;
    if(f.type==='cover'&&Math.abs(d-f.d)<f.width/2)q+=0.58;
  }
  return clamp(q,0,1.5);
}
R.surfaceAt=surfaceAt;
R.roughnessAt=roughnessAt;
R.inVillage=inVillage;
R.woodland=woodland;
R.clearance=clearance;
R.detailPlan={village,gates,humps,strips,repairs,covers,potholes,lots};
R.speedLimitAt=s=>inVillage(s)?20:60; // fictional signed road, not a real surveyed route
R.nextHump=(s,direction=1)=>{
  let best=null;
  for(const h of humps){const dist=mod((h.s-s)*direction,L);if(!best||dist<best.dist)best={...h,dist};}
  return best;
};
// A consistent surface function is shared by geometry, paint, tyres and traffic.
const basePoint=R.point;
R.point=function(s,d,up=0,out){const p=basePoint(s,d,up,out);p.y+=surfaceAt(s,d);return p;};
R.villageGround=(s,d,h,frame)=>{
  if(!inVillage(s,24)||Math.abs(d)<3.12||Math.abs(d)>40)return h;
  const longitudinal=smoothstep(village.start-24,village.start+5,s)*(1-smoothstep(village.end-5,village.end+24,s));
  const lateral=1-smoothstep(26,40,Math.abs(d));
  return E.lerp(h,frame.y+R.crown(3)+0.055,longitudinal*lateral);
};
const baseTerrain=R.terrainHeight;
R.terrainHeight=function(x,z){
  const h=baseTerrain(x,z), near=R.nearest(x,z);
  return near?R.villageGround(mod(near.s,L),near.d,h,{y:near.y}):h;
};
// Fix the sub-metre loop seam: sampled route distance and wrap length must agree.
R.sampleDistance=R.length/R.R.n;
E.VERSION='0.3.0-scenery';
