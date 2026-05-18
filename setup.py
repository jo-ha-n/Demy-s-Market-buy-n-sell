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


def generate_insert_sql(columns: list[str], table: str) -> str:
    placeholder = str.join(", ", (["%s"] * len(columns)))
    columns_str = str.join(", ", columns)

    return f"""INSERT INTO {table}({columns_str}) VALUES ({placeholder})"""


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
def is_db_exists(cursor: MySQLCursorAbstract, db_name: str) -> bool:
    cursor.execute(
        f"SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{db_name}'"
    )

    return cursor.fetchone()[0] == 1


@error_handling_sql
def insert_data_from_json(cursor: MySQLCursorAbstract, path: os.PathLike[str]) -> None:
    with open(path, 'r', encoding="utf-8") as file:
        data = json.load(file)

    if not isinstance(data, list):
        print(f"ERROR: Expected a list of objects in {path}, got {type(data).__name__}")
        return

    print(f"{len(data)} tables found! Inserting Values")

    for tables in data:
        try:
            columns = tables["columns"]
            values = tables["values"]
            table_name = tables["table"]

            print(f" - Adding values in table {table_name}")
        except KeyError as e:
            print(f"ERROR: Missing key {e} in {path}")
            return

        cursor.executemany(generate_insert_sql(columns, table_name), values)

    cursor._connection.commit()


@error_handling_sql
def execute_sql_script_from_file(
    cursor: MySQLCursorAbstract, path: os.PathLike[str]) -> None:
    with open(path, encoding="utf-8") as f:
        content = f.read()

    # split the sql statements then exec them one by one instead of exec the script as a whole
    [
        cursor.execute(cmd) for cmd in [
            cmd.strip() for cmd in content.split(';') if cmd.strip()
        ]
    ]


def get_files(path: os.PathLike[str], endswith: str) -> list[str]:
    return [ 
        os.path.join(path, file) for file in os.listdir(path)
        # os.path.exists seems overkill 
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
    mysql_connection.close()


if __name__ == "__main__":
    init()