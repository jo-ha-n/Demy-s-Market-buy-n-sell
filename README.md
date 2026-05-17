# Demy-s-Market-buy-n-sell

### Prerequisites

- Python 3.12+
- Xampp/PHP 8.2.12+

<br>

### Setting Up
run `setup.py` for setting up essentials such as making the database and its tables.

> Note: Make sure that is your mysql service is running on xampp

This will also include the insertions of dummy data that can be found in `data/dummy_data`
```
python setup.py
```

This set up script will look for the schema of the tables in `sql/setup/tables.sql` Where it would execute the scripts into the default database `demy_db`

<br>

### Custom Dummy Data
If you want to edit and make your own data entry that will be added into the tables, you can make your own json folder in `data/dummy_data`

> Note: If your json's format is incorrect the script will only print the error and skip said json

This is an example format

```json
{
    "table": "Users",
    "columns": [
        "userID", "email", "username", "password", "address", "contact_number", "role"
    ],
    "values": [
        [1, "demy@email.com", "demy", "demy_password", "Etivac", "0999999", "buyer"],
        [2, "lawrence@email.com", "lawrence", "lawrence_password", "Etivac", "0999999", "seller"],
        [3, "james@email.com", "james", "james_password", "Etivac", "0999999", "buyer"],
        [4, "vince@email.com", "vince", "vince_password", "Etivac", "0999999", "buyer"]
    ]
}
```
The format is pretty simple "table" for the table name. "columns" is the list of the non optional columns that you need, you can omit the optional columns. And the values is a list of lists of values to be inserted on the specified table.

> Note: If there's an error in the sql statement e.g the table name is not found or incorrect columns the script will only print the error and will also skip the rest of the json.

