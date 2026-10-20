"""Seed a stock forum with demo content for screenshots: python3 seed-demo.py URL ADMIN PASSWORD."""
import json, sys, urllib.error, urllib.request

base, user, password = sys.argv[1:4]

def call(method, path, body=None, token=None):
    req = urllib.request.Request(base + '/api' + path, method=method, data=json.dumps(body).encode() if body else None)
    req.add_header('Content-Type', 'application/vnd.api+json')
    if token:
        req.add_header('Authorization', 'Token ' + token)
    try:
        with urllib.request.urlopen(req) as r:
            return json.loads(r.read() or b'{}')
    except urllib.error.HTTPError as e:
        raise SystemExit(f'{method} {path}: {e.code} {e.read().decode()[:400]}')

token = call('POST', '/token', {'identification': user, 'password': password})['token']
api = lambda m, p, b=None: call(m, p, b, token)

def tag(name, slug, color, icon):
    return api('POST', '/tags', {'data': {'type': 'tags', 'attributes': {'name': name, 'slug': slug, 'color': color, 'icon': icon, 'description': '', 'isPrimary': True}}})['data']['id']

builds = tag('Builds', 'builds', '#4a7bd0', 'fas fa-tools')
guides = tag('Guides', 'guides', '#3c9a5f', 'fas fa-book')
premium = tag('Workshop', 'workshop', '#b8860b', 'fas fa-crown')

users = {}
for name in ['maya', 'theo', 'priya', 'jonas']:
    d = api('POST', '/users', {'data': {'type': 'users', 'attributes': {'username': name, 'email': name + '@example.com', 'password': 'demo-password-1', 'isEmailConfirmed': True}}})
    users[name] = d['data']['id']

def as_user(name):
    t = call('POST', '/token', {'identification': name, 'password': 'demo-password-1'})['token']
    return lambda m, p, b=None: call(m, p, b, t)

def discussion(author, title, body, tags, replies):
    d = as_user(author)('POST', '/discussions', {'data': {'type': 'discussions', 'attributes': {'title': title, 'content': body},
        'relationships': {'tags': {'data': [{'type': 'tags', 'id': t} for t in tags]}}}})
    did = d['data']['id']
    for who, text in replies:
        as_user(who)('POST', '/posts', {'data': {'type': 'posts', 'attributes': {'content': text}, 'relationships': {'discussion': {'data': {'type': 'discussions', 'id': did}}}}})
    return did

discussion('maya', 'Finally finished my custom water-cooled build',
    "After three months of planning, the loop is done. Temperatures under load dropped by eleven degrees compared with the old air cooler, and it is close to silent at idle.\n\n"
    "![The finished build](https://picsum.photos/seed/build/800/420)\n\n"
    "Full parts list and the order I assembled everything in are in [the spreadsheet](https://docs.example.com/parts). The fittings guide I followed is at https://fittings.example.com/guide.\n\n"
    "Happy to answer questions about the bending, the leak test or the fan curve.",
    [builds], [
        ('theo', "Great write-up. I tried the same pump last year and had to swap the fittings twice before it stopped dripping. Which reservoir did you end up going with?"),
        ('priya', "The bends look really clean. Did you use a silicone insert or sand? And how long did the leak test run before you powered it on?"),
        ('maya', "Silicone insert, heated slowly with a heat gun. Leak test ran for 24 hours with paper towels under every joint, which I recommend to anyone."),
        ('jonas', "Saving this thread. Eleven degrees is more than I expected from a single 360 radiator, nice result."),
    ])
discussion('priya', 'Beginner guide: choosing your first soldering iron',
    "If you are just getting started, a temperature-controlled iron is the single best upgrade you can make. Here is what to look for, and the three mistakes I made with my first one.",
    [guides], [
        ('theo', "Adding one: buy decent solder. Cheap lead-free solder makes everything harder than it needs to be."),
        ('jonas', "Tip cleaning matters more than people think. Brass wool, not a wet sponge."),
    ])
discussion('theo', 'Members workshop: tuning a CNC router for hardwood',
    "This month's workshop covers feeds and speeds for oak and walnut, with the settings file I use and a video of the first cuts.",
    [premium], [
        ('maya', "The chip load table alone was worth it. My walnut finish is much cleaner now."),
        ('priya', "Could you share the post-processor you used for the controller?"),
    ])
print('seeded', builds, guides, premium, users)
