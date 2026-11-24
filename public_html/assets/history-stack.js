(()=>{
 const stage=document.querySelector('.history-photo-stack-stage');if(!stage)return;
 const cards=[...stage.querySelectorAll(':scope > .history-photo-stack-card')];let order=[...cards],gesture=null,busy=false;
 const reduced=matchMedia('(prefers-reduced-motion:reduce)');
 function layout(){const unit=stage.clientWidth/230;order.forEach((card,rank)=>{card.style.zIndex=String(cards.length-rank);card.style.visibility=rank<4?'visible':'hidden';const depth=Math.min(rank,3);card.style.transform=`translate(${depth*7*unit}px,${depth*5*unit}px) rotate(${depth*3}deg) scale(${1-depth*.035})`;card.style.pointerEvents=rank===0?'auto':'none';card.setAttribute('aria-hidden',String(rank!==0))});stage.setAttribute('aria-label',`Memórias da Natu Vale. Foto ${cards.indexOf(order[0])+1} de ${cards.length}. Arraste para trocar ou use as setas do teclado.`)}
 async function advance(direction=1){if(busy)return;busy=true;const card=order[0];
  if(!reduced.matches){const animation=card.animate([{transform:card.style.transform,opacity:1},{transform:`translate(${direction*stage.clientWidth*1.22}px,0) rotate(${direction*30}deg)`,opacity:0}],{duration:260,easing:'ease-in'});await animation.finished.catch(()=>{});animation.cancel()}
  order.push(order.shift());layout();busy=false;
 }
 function startDrag(e){if(e.pointerType==='touch')return;if(busy||!e.isPrimary||(e.pointerType==='mouse'&&e.button!==0))return;gesture={id:e.pointerId,x:e.clientX,y:e.clientY,active:false};if(e.pointerId>=0)stage.setPointerCapture(e.pointerId);}
 function moveDrag(e){if(!gesture||gesture.id!==e.pointerId)return;const dx=e.clientX-gesture.x,dy=e.clientY-gesture.y;
  if(!gesture.active){if(Math.abs(dy)>12&&Math.abs(dy)>Math.abs(dx)){gesture=null;return}if(Math.abs(dx)<8)return;gesture.active=true;if(e.pointerId>=0)stage.setPointerCapture(e.pointerId);order[0].classList.add('is-dragging')}
  order[0].style.transform=`translate(${dx}px,${dy*.15}px) rotate(${Math.max(-30,Math.min(30,dx/stage.clientWidth*30))}deg)`;
 }
 stage.addEventListener('pointerdown',startDrag);
 stage.addEventListener('pointermove',moveDrag);
 function finish(e,cancel=false){if(!gesture||gesture.id!==e.pointerId)return;const dx=e.clientX-gesture.x,active=gesture.active;gesture=null;order[0].classList.remove('is-dragging');if(e.pointerId>=0&&stage.hasPointerCapture(e.pointerId))stage.releasePointerCapture(e.pointerId);if(!cancel&&active&&Math.abs(dx)>=90)advance(dx>0?1:-1);else layout()}
 // Handle touch directly so native scroll arbitration cannot cancel a sideways swipe.
 const touchPoint=t=>({pointerId:-1,pointerType:'direct-touch',isPrimary:true,clientX:t.clientX,clientY:t.clientY});
 stage.addEventListener('touchstart',e=>{if(e.touches.length===1)startDrag(touchPoint(e.touches[0]))},{passive:true});
 stage.addEventListener('touchmove',e=>{if(!gesture||gesture.id!==-1)return;moveDrag(touchPoint(e.touches[0]));if(gesture?.active)e.preventDefault()},{passive:false});
 stage.addEventListener('touchend',e=>{if(e.changedTouches.length)finish(touchPoint(e.changedTouches[0]))},{passive:true});
 stage.addEventListener('touchcancel',e=>{if(e.changedTouches.length)finish(touchPoint(e.changedTouches[0]),true)},{passive:true});
 stage.addEventListener('pointerup',e=>finish(e));stage.addEventListener('pointercancel',e=>finish(e,true));
 // Touch starts with implicit capture on the front card. Its capture-loss event
 // bubbles when capture moves to the stage; only the stage losing it ends a drag.
 stage.addEventListener('lostpointercapture',e=>{if(e.target===stage&&gesture&&gesture.id===e.pointerId){gesture=null;order[0].classList.remove('is-dragging');layout()}});
 stage.addEventListener('keydown',e=>{if(!['ArrowLeft','ArrowRight'].includes(e.key))return;e.preventDefault();advance(e.key==='ArrowRight'?1:-1)});
 stage.addEventListener('dragstart',e=>e.preventDefault());new ResizeObserver(()=>{if(!gesture&&!busy)layout()}).observe(stage);layout();
 const viewer=document.createElement('dialog');viewer.className='history-photo-viewer';
 viewer.setAttribute('aria-label','Foto ampliada da história da Natu Vale');
 viewer.innerHTML='<button type="button" class="history-photo-viewer-close" aria-label="Fechar foto ampliada">×</button><img alt="">';
 document.body.append(viewer);
 const large=viewer.querySelector('img'),closeButton=viewer.querySelector('button');
 let origin=null,closing=false,opening=false,photoAnimation=null,lastFocus=null;
 function zoomTransform(){const from=origin?.getBoundingClientRect(),to=large.getBoundingClientRect();
  if(!from||!to.width||!to.height)return 'none';
  return `translate(${from.left+from.width/2-to.left-to.width/2}px,${from.top+from.height/2-to.top-to.height/2}px) scale(${from.width/to.width},${from.height/to.height})`;
 }
 async function enlarge(){
  if(viewer.open||opening||busy||gesture?.active)return;
  opening=true;origin=order[0];const img=origin.querySelector('img');large.src=img.currentSrc||img.src;large.alt=img.alt;
  try{await large.decode()}catch{}
  lastFocus=document.activeElement;viewer.showModal();
  if(!reduced.matches){
   photoAnimation=large.animate([{transform:zoomTransform(),opacity:.4},{transform:'none',opacity:1}],{duration:420,easing:'cubic-bezier(.22,1,.36,1)'});
  }
  closeButton.focus({preventScroll:true});opening=false;
 }
 async function closePhoto(){if(!viewer.open||closing)return;closing=true;
  photoAnimation?.cancel();
  if(!reduced.matches){
   viewer.classList.add('is-closing');
   const motion=large.animate([{transform:'none',opacity:1},{transform:zoomTransform(),opacity:0}],{duration:280,easing:'cubic-bezier(.4,0,.6,1)'});
   await motion.finished.catch(()=>{});
  }
  viewer.close();viewer.classList.remove('is-closing');closing=false;lastFocus?.focus({preventScroll:true});
 }
 stage.addEventListener('dblclick',event=>{event.preventDefault();enlarge()});
 stage.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();enlarge()}});
 let tapStart=null,lastTap=null;
 stage.addEventListener('pointerdown',event=>{if(event.pointerType==='mouse')return;tapStart={x:event.clientX,y:event.clientY,id:event.pointerId}});
 stage.addEventListener('pointercancel',()=>{tapStart=null;lastTap=null});
 stage.addEventListener('pointerup',event=>{
  if(!tapStart||tapStart.id!==event.pointerId)return;
  const tap=tapStart;tapStart=null;
  if(Math.hypot(event.clientX-tap.x,event.clientY-tap.y)>10){lastTap=null;return}
  const now=performance.now();
  if(lastTap&&now-lastTap.time<350&&Math.hypot(event.clientX-lastTap.x,event.clientY-lastTap.y)<24){lastTap=null;event.preventDefault();enlarge()}
  else lastTap={time:now,x:event.clientX,y:event.clientY};
 });
 closeButton.addEventListener('click',closePhoto);
 viewer.addEventListener('click',event=>{if(event.target===viewer)closePhoto()});
 viewer.addEventListener('cancel',event=>{event.preventDefault();closePhoto()});
 window.addEventListener('keydown',event=>{if(viewer.open&&event.key==='Escape'){event.preventDefault();event.stopImmediatePropagation();closePhoto()}},true);
})();
