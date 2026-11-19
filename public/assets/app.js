document.addEventListener('DOMContentLoaded',function(){
 document.querySelectorAll('form[onsubmit]').forEach(function(form){form.addEventListener('submit',function(e){if(!confirm('Are you sure you want to delete this record?'))e.preventDefault();});});
 document.querySelectorAll('[data-current-year]').forEach(function(el){el.textContent=new Date().getFullYear();});
 document.querySelectorAll('.attendance-form select').forEach(function(select){select.addEventListener('change',function(){select.classList.toggle('status-absent',select.value==='absent');});});
});
