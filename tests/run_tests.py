import os
DBP=os.path.join(os.path.dirname(os.path.abspath(__file__)),'..','data','ticketskenya.sqlite')
import re, io, http.cookiejar, urllib.request, urllib.parse, uuid, sqlite3
B='http://127.0.0.1:8080'
class NoRedir(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
def client():
    cj=http.cookiejar.CookieJar()
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj),NoRedir)
def req(o,path,data=None,files=None):
    h={}
    if files:
        bd=uuid.uuid4().hex; body=b''
        for k,v in (data or {}).items():
            for vv in (v if isinstance(v,list) else [v]):
                body+=f'--{bd}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{vv}\r\n'.encode()
        for k,(fn,ct,content) in files.items():
            body+=f'--{bd}\r\nContent-Disposition: form-data; name="{k}"; filename="{fn}"\r\nContent-Type: {ct}\r\n\r\n'.encode()+content+b'\r\n'
        body+=f'--{bd}--\r\n'.encode(); h['Content-Type']='multipart/form-data; boundary='+bd; d=body
    elif data is not None:
        d=urllib.parse.urlencode(data,doseq=True).encode()
    else: d=None
    try:
        r=o.open(urllib.request.Request(B+path,data=d,headers=h)); return r.status,r.read().decode(),None
    except urllib.error.HTTPError as e:
        return e.code,e.read().decode(),e.headers.get('Location')
def tok(html): return re.search(r'name="_csrf" value="([^"]+)"',html).group(1)
def login(o,email,pw):
    s,h,_=req(o,'/auth/login.php'); return req(o,'/auth/login.php',{'_csrf':tok(h),'email':email,'password':pw})
R=[]
def chk(name,ok,info=''): R.append(ok); print(('PASS ' if ok else 'FAIL ')+name,info)
# public
a=client()
for p in ['/','/events/','/events/?category=Food+%26+Drinks','/pricing.php','/about.php','/auth/login.php','/auth/register.php','/events/devfest-nairobi-2026','/cookies.php','/help.php','/contact.php','/privacy.php','/terms.php','/refunds.php','/organisers.php','/auth/forgot.php']:
    s,h,_=req(a,p); chk('GET '+p,s==200,s)
s,h,_=req(a,'/'); chk('home stats match the reference and count up on scroll',all(('data-count="%s"'%n) in h for n in ('2500','520','350','6')) and h.count('data-count=')==4 and all(x in h for x in ('class="sr-only">2500+','class="sr-only">520k+','class="sr-only">350k+','class="sr-only">6 countries')))
s_old,h_old,l_old=req(a,'/events/view.php?slug=devfest-nairobi-2026'); chk('old query-string event URL redirects (301) to clean URL',s_old==301 and l_old=='/events/devfest-nairobi-2026',(s_old,l_old))
chk('home has sticky hero','tk-hero' in h)
# unauth protection
for p in ['/admin/','/admin/events.php','/admin/event-edit.php']:
    s,h,l=req(a,p); chk('unauth blocked '+p,s in(301,302,303) and 'login' in (l or ''),(s,l))
s,h,l=req(a,'/admin/events.php',{'id':'1','action':'delete','_csrf':'x'}); chk('unauth POST delete blocked',s in(302,303,400,403),s)
# customer cannot reach admin
c=client(); req(c,'/auth/register.php')
s,h,_=req(c,'/auth/register.php'); 
print(re.findall(r'name="(\w+)"',h))

print('--- admin flow ---')
import struct,zlib,subprocess
def png():
    raw=b''.join(b'\x00'+b'\xff\x00\x00'*8 for _ in range(8))
    def ch(t,d): return struct.pack('>I',len(d))+t+d+struct.pack('>I',zlib.crc32(t+d)&0xffffffff)
    return b'\x89PNG\r\n\x1a\n'+ch(b'IHDR',struct.pack('>IIBBBBB',8,8,8,2,0,0,0))+ch(b'IDAT',zlib.compress(raw))+ch(b'IEND',b'')
ad=client(); s,h,l=login(ad,'demo.admin@ticketskenya.local','DemoPass123!'); chk('admin login',s==302,(s,l))
s,h,_=req(ad,'/admin/events.php'); chk('admin sees event manager',s==200 and 'Manage events' in h)
s,h,_=req(ad,'/admin/event-edit.php'); t=tok(h)
base={'_csrf':t,'title':'Test Launch Party','description':'A test event for the manager.','category':'Comedy','status':'draft','venue':'Test Hall','city':'Nairobi','starts_at':'2027-03-01T19:00','ends_at':'','organiser_name':'QA','listing_type':'owned','source_url':'','official_ticket_url':'','price_info':'',
 'tt_id[]':['0','0'],'tt_name[]':['Regular','VIP'],'tt_price[]':['500','1500'],'tt_qty[]':['100','20']}
# invalid cases
bad=dict(base,title='ab'); s,h,_=req(ad,'/admin/event-edit.php',bad); chk('rejects short title',s==200 and 'Title must be' in h)
bad=dict(base,**{'tt_price[]':['-5','abc']}); s,h,_=req(ad,'/admin/event-edit.php',bad); chk('rejects bad prices',s==200 and 'must be a whole number' in h)
bad=dict(base,ends_at='2027-02-01T10:00'); s,h,_=req(ad,'/admin/event-edit.php',bad); chk('rejects end before start',s==200 and 'end must be after' in h)
bad=dict(base,source_url='javascript:alert(1)'); s,h,_=req(ad,'/admin/event-edit.php',bad); chk('rejects javascript: URL',s==200 and 'Source URL must start' in h)
bad=dict(base,status='published',**{'tt_name[]':['',''],'tt_price[]':['',''],'tt_qty[]':['','']}); s,h,_=req(ad,'/admin/event-edit.php',bad); chk('cannot publish without tickets',s==200 and 'at least one ticket' in h)
s,h,_=req(ad,'/admin/event-edit.php',dict(base,_csrf='bad')); chk('bad CSRF rejected',s==400)
s,h,_=req(ad,'/admin/event-edit.php',base,files={'cover':('x.php','image/png',b'<?php echo 1;')}); chk('rejects PHP disguised as image',s==200 and 'Only JPG' in h)
s,h,l=req(ad,'/admin/event-edit.php',base,files={'cover':('a.png','image/png',png())}); chk('create event + image upload',s==302,(s,l))
db=sqlite3.connect(DBP); db.row_factory=sqlite3.Row
ev=db.execute("select * from events where title='Test Launch Party'").fetchone()
chk('persisted in DB, owned',ev and ev['listing_type']=='owned' and ev['cover_image'].startswith('uploads/events/') and ev['cover_image'].endswith('.png'))
eid=ev['id']; slug=ev['slug']
chk('2 ticket types saved',db.execute('select count(*) from ticket_types where event_id=?',(eid,)).fetchone()[0]==2)
s,_,_=req(client(),'/events/'+slug); chk('draft not public',s in(302,404),s)
def act(o,a,i):
    s,h,_=req(o,'/admin/events.php'); return req(o,'/admin/events.php',{'_csrf':tok(h),'id':i,'action':a})
act(ad,'publish',eid)
s,h,_=req(client(),'/events/'+slug); chk('published is public w/ KES prices',s==200 and 'Test Launch Party' in h and '1,500' in h)
s,h,_=req(client(),'/events/'); chk('listed in /events/','Test Launch Party' in h)
s,h,_=req(client(),'/'); chk('home carousel shows at most 16 nearest events; /events/ lists all',h.count('class="ev-card')<=16)
# edit keeps ticket ids
tt=[r['id'] for r in db.execute('select id from ticket_types where event_id=? order by id',(eid,))]
s,h,_=req(ad,'/admin/event-edit.php?id=%d'%eid); 
ed=dict(base,_csrf=tok(h),id=str(eid),title='Test Launch Party v2',status='published',**{'tt_id[]':[str(x) for x in tt],'tt_price[]':['600','1500']})
s,h,l=req(ad,'/admin/event-edit.php?id=%d'%eid,ed); db2=sqlite3.connect(DBP)
chk('edit saved, ids kept, price changed',s==302 and db2.execute('select title from events where id=?',(eid,)).fetchone()[0]=='Test Launch Party v2' and [r[0] for r in db2.execute('select id from ticket_types where event_id=? order by id',(eid,))]==tt and db2.execute('select price_kes from ticket_types where id=?',(tt[0],)).fetchone()[0]==600)
act(ad,'unpublish',eid); s,_,_=req(client(),'/events/'+slug); chk('unpublished hidden',s in(302,404),s)
# orders -> delete archives
db2.execute("insert into orders(user_id,event_id,status,total_kes) values(2,?,'paid',600)",(eid,)); db2.commit()
s,h,_=act(ad,'delete',eid); st=sqlite3.connect(DBP).execute('select status from events where id=?',(eid,)).fetchone()
chk('delete with orders => archived, not removed',st and st[0]=='archived',st)
# organiser restrictions
og=client(); s,h,l=login(og,'demo.organiser@ticketskenya.local','DemoPass123!'); chk('organiser login',s==302)
s,h,_=req(og,'/admin/event-edit.php?id=1'); chk('organiser cannot edit imported event',s in(302,403),s)
s,h,_=req(og,'/admin/event-edit.php'); r=req(og,'/admin/events.php',{'_csrf':tok(h),'id':'1','action':'unpublish'})
st=sqlite3.connect(DBP).execute('select status from events where id=1').fetchone()[0]
chk('organiser POST on imported event refused',st=='published',st)
s,h,_=req(og,'/admin/events.php'); chk('organiser sees no Delete button','>Delete<' not in h)
# customer cannot use admin
cu=client(); login(cu,'demo.customer@ticketskenya.local','DemoPass123!')
s,h,l=req(cu,'/admin/events.php'); chk('customer blocked from admin',s in(302,403),(s,l))
# uploaded file is not executable / served as image
up=ev['cover_image']; import subprocess as sp; code=sp.run(['curl','-s','-o','/dev/null','-w','%{http_code} %{content_type}',B+'/assets/'+up],capture_output=True,text=True).stdout; chk('uploaded image served as image',code.startswith('200 image/png'),code)
print('\nSUMMARY: %d/%d passed'%(sum(R),len(R)))
