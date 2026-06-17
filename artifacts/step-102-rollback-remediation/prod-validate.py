#!/usr/bin/env python3
"""STEP 103 production post-deploy validator. No secrets embedded.
Run:  WPCC_PROD_TOKEN=<full-token> python3 prod-validate.py
Targets https://mosharafmanu.com. Performs only a safe, self-reversing write."""
import json, os, sys, urllib.request, urllib.error
BASE="https://mosharafmanu.com/wp-json/wp-command-center/v1"; MCP=BASE+"/mcp"
TOKEN=os.environ.get("WPCC_PROD_TOKEN","")
if not TOKEN: sys.exit("set WPCC_PROD_TOKEN")
_id=[0]
def _post(u,p,t=40):
    b=json.dumps(p).encode();r=urllib.request.Request(u,data=b,method="POST",headers={"Authorization":"Bearer "+TOKEN,"Content-Type":"application/json"})
    try:
        with urllib.request.urlopen(r,timeout=t) as x:return x.status,x.read().decode()
    except urllib.error.HTTPError as e:return e.code,e.read().decode()
def mcp(tool,args=None,method="tools/call"):
    _id[0]+=1
    params={"name":tool,"arguments":args or {}} if method=="tools/call" else (args or {})
    c,raw=_post(MCP,{"jsonrpc":"2.0","id":_id[0],"method":method,"params":params})
    j=json.loads(raw);res=j.get("result",{})
    if method!="tools/call":return res
    t=res.get("content",[{}])[0].get("text","") if res.get("content") else ""
    try:d=json.loads(t)
    except:d=None
    return {"isError":bool(res.get("isError")),"data":d,"text":t}
def fv(o,k):
    if isinstance(o,dict):
        if k in o:return o[k]
        for v in o.values():
            x=fv(v,k)
            if x is not None:return x
    return None
R={}
# 2/3. MCP health + tool discovery
tl=mcp(None,{},"tools/list"); R["mcp_endpoint_healthy"]=bool(tl.get("tools")); R["tool_discovery"]=f"{len(tl.get('tools',[]))} tools"
# 1. deployed-commit proxy: rollback_available only exists post-a819f4f
# 4. read-only
si=mcp("system_info",{}); R["readonly_system_info"]= (not si["isError"] and bool(fv(si["data"],"php_version")))
# 5. safe write->rollback (low-impact registered option, self-reversing)
og=mcp("option_manage",{"action":"option_get","option_id":"posts_per_page"});orig=fv(og["data"],"current_value")
nv=(int(orig)+1) if str(orig).isdigit() else 11
u=mcp("option_manage",{"action":"option_update","option_id":"posts_per_page","value":nv,"reason":"STEP103 prod smoke"})
rid=fv(u["data"],"rollback_id");avail=fv(u["data"],"rollback_available")
rb=mcp("option_manage",{"action":"option_rollback","option_id":"posts_per_page","rollback_id":rid})
ch=mcp("option_manage",{"action":"option_get","option_id":"posts_per_page"})
restored=str(fv(ch["data"],"current_value"))==str(orig)
R["write_rollback"]={"rollback_id":bool(rid),"rollback_available":bool(avail),"restored":restored}
R["deployed_commit_a819f4f"]= bool(avail)  # new field => commit live
# 6. approval gating + audit + timeline
rc=mcp("approval_manage",{"action":"request_create","operation_id":"option_manage","payload":{"action":"option_update","option_id":"posts_per_page","value":nv}})
req=fv(rc["data"],"request_id")
pre=mcp("approval_manage",{"action":"queue_run","request_id":req})
R["approval_gating"]=bool(pre["isError"])
if req: mcp("approval_manage",{"action":"request_cancel","request_id":req})
au=mcp("report_manage",{"action":"report_agent_activity","limit":100}); R["audit"]=(not au["isError"] and "operations" in au["text"])
c,raw=_post(BASE+"/agent/timeline?limit=5",{}) if False else (None,None)
r=urllib.request.Request(BASE+"/agent/timeline?limit=5",headers={"Authorization":"Bearer "+TOKEN})
try:
    with urllib.request.urlopen(r,timeout=30) as x: tl2=x.read().decode();tc=x.status
except Exception as e: tl2=str(e);tc=-1
R["timeline"]=(tc==200 and "operation" in tl2)
print(json.dumps(R,indent=2))
json.dump(R,open(os.path.join(os.path.dirname(__file__),"prod-validation-results.json"),"w"),indent=2)
ok = R["mcp_endpoint_healthy"] and R["readonly_system_info"] and R["write_rollback"]["restored"] and R["write_rollback"]["rollback_available"] and R["approval_gating"] and R["audit"] and R["timeline"]
print("\nVERDICT:", "DEPLOYMENT SUCCESSFUL" if ok else "NEEDS REVIEW")
