const appBase=document.querySelector('meta[name="analytics-base"]').content;
const loginUrl=appBase?appBase+'/index.php?route=%2Fapi%2Flogin':'/api/login';
document.querySelector('#login-form').addEventListener('submit',async event=>{
  event.preventDefault();
  const button=event.target.querySelector('button');button.disabled=true;
  try{
    const response=await fetch(loginUrl,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({password:document.querySelector('#password').value})});
    if(response.ok){location.href=appBase+'/';return;}
    const result=await response.json();document.querySelector('#error').textContent=result.detail||'Не удалось войти';
  }catch{document.querySelector('#error').textContent='Нет связи с сервером';}
  finally{button.disabled=false;}
});
