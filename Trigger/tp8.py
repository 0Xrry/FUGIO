import requests
import base64

data = base64.b64encode(b'O:17:"dummy_class_r353t":1:{s:12:"used_methods";a:0:{}}')


url = "http://127.0.0.1:7272/tp8/public/info.php".format(data)

r = requests.get(url=url)

print(r.text)