import json

table = None
columns = None
values = None

with open("./dummy_data/users.json", 'r', encoding='utf-8') as f:
    data = json.load(f)

    table = data["table"]
    columns = data["columns"]
    values = data["values"]


palceholder = str.join(", ",(["%s"] * len(columns)))
columns = str.join(', ', columns)
command = f"INSERT INTO {table}({columns}) VALUES ({palceholder})"
print(values)

print(command)