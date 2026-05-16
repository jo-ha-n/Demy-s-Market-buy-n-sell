# Set up necessary prequisite dbs for the buy and sell website
import os, sys, subprocess, json, sqlite3
from typing import Callable, Any

# Import mysql connector, pip install if it does not exists
package = "mysql-connector-python"
try:
    import mysql.connector
except ImportError:
    # You also need to add pip to your path in order to automatically add the missing dependency
    # otherwise just manually pip install it
    print(f"Package {package} not foud, Installing {package}.....")
    subprocess.check_call([sys.executable, '-m', 'pip', 'install', package])
finally:
    import mysql.connector
    from mysql.connector.abstracts import MySQLCursorAbstract


def generate_insert_sql(columns: dict[str], table: str) -> str:
    palceholder = str.join(", ",(["%s"] * len(columns)))
    columns = str.join(", ", columns)

    return f"""INSERT INTO {table}({columns}) VALUES ({palceholder})"""

def error_handling_sql(func: Callable[..., Any]) -> Callable[..., Any]:
    def wrapper(*args: Any, **kwargs: Any) -> Any:
        cursor = next(
            (
                arg for arg in list(args) + list(kwargs.values())
                if isinstance(arg, (MySQLCursorAbstract, sqlite3.Cursor))
            ),
            None,
        )

        try:
            return func(*args, **kwargs)

        except mysql.connector.Error as e:
            if cursor is None or isinstance(cursor, MySQLCursorAbstract):
                print(f"MySQL ERROR: {e.errno} - {e.msg}")

        except sqlite3.Error as e:
            if cursor is None or isinstance(cursor, sqlite3.Cursor):
                print(f"SQLite ERROR: {e}")

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
def insert_data_from_json(cursor: MySQLCursorAbstract | sqlite3.Cursor, path: os.PathLike[str]) -> None:
    columns = None
    values = None
    table = None

    with open(path, 'r', encoding="utf-8") as file:
        data = json.load(file)

        try:
            columns = data["columns"]
            values = data["values"]
            table = data["table"]
        except KeyError as e:
            print(e)
            return
        
    cursor.executemany(generate_insert_sql(columns, table), values)
    cursor._connection.commit()

@error_handling_sql
def execute_sql_script_from_file(cursor: MySQLCursorAbstract | sqlite3.Cursor, path: os.PathLike[str]) -> None:
    # Assuming sql command lines ends with a semi colon
    with open(path, encoding="utf-8") as f:
        commands = f.read().split(';')

    for line in commands:
        cursor.execute(line)

def get_files(path: os.PathLike[str], endswith: str) -> list[str]:
    return [
        os.path.join(path, file) for file in os.listdir(path) 
        if file.endswith(endswith) and os.path.exists(os.path.join(path, file))
    ]

def init():
    creds = {"host":"localhost", "user":"root", "pass":""}
    arg_len = len(sys.argv)

    if(arg_len == 1):
        print("No arguments found using default values")
    elif(arg_len == 4):
        creds["host"] == sys.argv[1]
        creds["user"] == sys.argv[2]
        creds["pass"] == sys.argv[3]
    else:
        print(f"Usage: {os.path.basename(__file__)} 'host' 'user' 'password'")
        sys.exit()

    print("Credentials:")
    print(f"\t- host: '{creds['host']}'")
    print(f"\t- user: '{creds['user']}'")
    print(f"\t- pass: '{creds['pass']}'")

    root_path = os.path.dirname(__file__)
    schema_path = os.path.join(root_path, "sql", "setup", "tables.sql")
    data_path = os.path.join(root_path, "data") 
    dummy_data_path = os.path.join(data_path, "dummy_data")
    test_data_path = os.path.join(data_path, "test_data")

    """
        NOTE: The script will look for sql files inside sql/setup, these scripts are only put 
              there if it is necessary for the set up of the website otherwise don't put them
              there.
    """

    mysql_connection = mysql.connector.connect(
        host=creds['host'],
        user=creds['user'],
        password=creds['pass']
    )
    mysql_cursor = mysql_connection.cursor()
    
    db_name = "demy_db"

    create_db(db_name)
    use_db(db_name)
    execute_sql_script_from_file(mysql_cursor, schema_path)

    """
        NOTE: Inside the dummy_data folders there are json files, inside the json files
              are the table name, the columns and the values that you wanted to insert.
    """

    print(f"Adding dummy data to {db_name} ......")

    if not os.path.exists(dummy_data_path):
        print(f"{dummy_data_path} PATH DOES NOT EXISTS!")
        return

    dummy_data_files = get_files(dummy_data_path, ".json")

    print(f"Found {len(dummy_data_files)} files of dummy data inserting....")

    for file in dummy_data_files:
        insert_data_from_json(mysql_cursor, file)

    mysql_connection.close()

    # Set up sqlite3 for testing
    print("Setting up SQLITE3.....")
    sqlite_connetion = sqlite3.connect(f"test_{db_name}.sqlite")
    sqlite_cursor = sqlite_connetion.cursor()

    execute_sql_script_from_file(sqlite_cursor, schema_path)
    
    test_data_files = get_files(test_data_path)

    for file in test_data_files:
        insert_data_from_json(sqlite_cursor, file)


if __name__ == "__main__":
    init()