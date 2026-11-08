# Writes agent/desks/jack-plan-names.txt from THIS Python's unicodedata (the office: Tower's Python 3.11, Unicode 14.0):
#   python3 -I gen-names.py > agent/desks/jack-plan-names.txt
import unicodedata,sys
out=[];marks=[];fold=[];low=[];start=prev=None
for c in range(sys.maxunicode+1):
    if 0xD800<=c<=0xDFFF: continue
    ch=chr(c)
    if unicodedata.combining(ch):
        if start is None: start=c
        elif c!=prev+1:
            marks.append((start,prev)); start=c
        prev=c
    if ch.casefold()!=ch: fold.append("%x,%s"%(c," ".join("%x"%ord(x) for x in ch.casefold())))
    if ch.lower()!=ch: low.append("%x,%s"%(c," ".join("%x"%ord(x) for x in ch.lower())))
    if 0xAC00<=c<=0xD7A3: continue
    d=unicodedata.normalize("NFKD",ch)
    if d!=ch: out.append("%x,%s"%(c," ".join("%x"%ord(x) for x in d)))
marks.append((start,prev))
print("#python %d.%d unicode %s"%(sys.version_info[0],sys.version_info[1],unicodedata.unidata_version))
print("\n".join(out)); print("#combining")
print("\n".join("%x-%x"%m if m[0]!=m[1] else "%x"%m[0] for m in marks))
print("#casefold"); print("\n".join(fold))
print("#lower"); print("\n".join(low))
