# Set up necessary prerequisite dbs for the buy and sell website
import os, sys, subprocess, json
from typing import Callable, Any

package = "mysql-connector-python"
try:
    import mysql.connector
except ImportError:
    print(f"Package {package} not found, Installing {package}.....")
    subprocess.check_call([sys.executable, '-m', 'pip', 'install', package])
finally:
    import mysql.connector
    from mysql.connector.abstracts import MySQLCursorAbstract


@DeprecationWarning
def generate_insert_sql(columns: list[str], values: list[str], table: str) -> str:
    combined_values = str.join(", ", values)
    combined_columns = str.join(", ", columns)

    return f"""INSERT INTO {table}({combined_columns}) VALUES ({combined_values})"""


def error_handling_sql(func: Callable[..., Any]) -> Callable[..., Any]:
    def wrapper(*args: Any, **kwargs: Any) -> Any:
        try:
            return func(*args, **kwargs)
        except mysql.connector.Error as e:
                print(f"MySQL ERROR: {e.errno} - {e.msg}")
        except Exception as e:
            print(f"Unexpected ERROR: {e}")

    return wrapper


@error_handling_sql
def hash_password(cursor: MySQLCursorAbstract, password: str):
    cursor.execute(f"SELECT PASSWORD('{password}')")

    return cursor.fetchone()[0]


@error_handling_sql
def update_user_password(cursor: MySQLCursorAbstract, pk: str, value: str) -> None:
    # Reminder to change this if the table name or column name are changed
    cursor.execute(f"UPDATE Users SET password = '{value}' WHERE userID = {pk}")

    
@error_handling_sql
def get_all_users(cursor: MySQLCursorAbstract):
    cursor.execute("SELECT * from Users")

    return cursor.fetchall()


@error_handling_sql
def create_db(cursor: MySQLCursorAbstract, db_name: str) -> None:
    cursor.execute(
        f"""CREATE DATABASE IF NOT EXISTS {db_name} 
            CHARACTER SET utf8mb4 
            COLLATE utf8mb4_unicode_ci"""
    )


@error_handling_sql
def use_db(cursor: MySQLCursorAbstract, db_name: str) -> None:
    cursor.execute(f"USE {db_name}")


@error_handling_sql
def drop_db(cursor: MySQLCursorAbstract, db_name: str) -> None:
    cursor.execute(f"DROP DATABASE IF EXISTS {db_name}")


@error_handling_sql
def is_db_exists(cursor: MySQLCursorAbstract, name: str) -> bool:
    cursor.execute(
        f"SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{name}'"
    )

    return cursor.fetchone()[0] == 1


@error_handling_sql
def is_table_exists(cursor: MySQLCursorAbstract, table_db: str, table_name: str) -> bool:
    cursor.execute(
        f"""SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE 
            TABLE_SCHEMA = '{table_db}' AND TABLE_NAME = '{table_name}'"""
    )

    return cursor.fetchone()[0] == 1


@error_handling_sql
def insert_all_values(cursor: MySQLCursorAbstract, columns: list[str], values: list[list[str]], table_name: str) -> None:
    for row in values:
        placeholders = []
        safe_row = []

        for val in row:
            if isinstance(val, str) and val.startswith("ST_"):
                placeholders.append(val)   # inject as raw SQL
            else:
                placeholders.append("%s")
                safe_row.append(val)

        sql = f"INSERT INTO {table_name} ({', '.join(columns)}) VALUES ({', '.join(placeholders)})"
        cursor.execute(sql, safe_row)


@error_handling_sql
def insert_data_from_json(cursor: MySQLCursorAbstract, path: os.PathLike[str]):
    with open(path, 'r', encoding="utf-8") as file:
        data = json.load(file)

    for tables in data:
        columns = tables["columns"]
        values  = tables["values"]
        table_name = tables["table"]

        print(f" - Adding values in table {table_name}")

        insert_all_values(cursor, columns, values, table_name)

    cursor._connection.commit()


@error_handling_sql
def execute_sql_script_from_file(
    cursor: MySQLCursorAbstract, path: os.PathLike[str]) -> None:
    with open(path, encoding="utf-8") as f:
        content = f.read()

    for cmd in [ cmd.strip() for cmd in content.split(';') if cmd.strip() ]:
        cursor.execute(cmd)


def get_files(path: os.PathLike[str], endswith: str) -> list[str]:
    return [ 
        os.path.join(path, file) for file in os.listdir(path)
        # os.path.exists seems overkill, omitted it for now
        # if file.endswith(endswith) and os.path.exists(os.path.join(path, file))
        if file.endswith(endswith)
    ] 


def init():
    creds = {"host": "localhost", "user": "root", "pass": ""}
    arg_len = len(sys.argv)

    if arg_len == 1:
        print("No arguments found, using default values")
    elif arg_len == 4:
        creds["host"] = sys.argv[1]
        creds["user"] = sys.argv[2]
        creds["pass"] = sys.argv[3]
    else:
        print(f"Usage: {os.path.basename(__file__)} 'host' 'user' 'password'")
        sys.exit()

    print("Credentials:")
    print(f" - host: '{creds['host']}'")
    print(f" - user: '{creds['user']}'")
    print(f" - pass: '{creds['pass']}'")

    root_path = os.path.dirname(__file__)
    schema_path = os.path.join(root_path, "sql", "setup", "tables.sql")
    data_path = os.path.join(root_path, "data", "dummy_data.json")

    mysql_connection = mysql.connector.connect(
        host=creds['host'],
        user=creds['user'],
        password=creds['pass']
    )
    mysql_cursor = mysql_connection.cursor()

    db_name = "demy_db"

    if is_db_exists(mysql_cursor, db_name):
        print(f"Existing '{db_name}' found!")
        print(f"Resetting '{db_name}'....")
        drop_db(mysql_cursor, db_name)

    create_db(mysql_cursor, db_name)
    use_db(mysql_cursor, db_name)
    execute_sql_script_from_file(mysql_cursor, schema_path)

    print(f"Adding dummy data to '{db_name}'....")

    if not os.path.exists(data_path):
        print(f"{data_path} PATH DOES NOT EXIST!")
        return

    insert_data_from_json(mysql_cursor, data_path)

    mysql_dict_cursor = mysql_connection.cursor(dictionary=True)

    # hash the passwords
<<<<<<< HEAD
    print("Hashing dummy passwords....")

=======
>>>>>>> 4a3a82e7d7940f51d7586161735a4b13c06c528d
    if is_table_exists(mysql_cursor, db_name, "Users"):
        users_with_hash = [ 
            ( entry["userID"], hash_password(mysql_cursor, entry["password"]) )
            for entry in get_all_users(mysql_dict_cursor) 
        ]

        for pk, hashed_password in users_with_hash:
            update_user_password(mysql_cursor, pk, hashed_password)

        mysql_connection.commit()

    mysql_connection.close()


if __name__ == "__main__":
    init()
    