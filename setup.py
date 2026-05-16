# Set up necessary prequisite dbs for the buy and sell website
import os, sys, subprocess, json
from typing import Callable, Any

# Import msql connector, pip install if it does not exists
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
    from mysql.connector.abstracts import MySQLConnectionAbstract


def error_handling_sql(func: Callable[...]) -> Callable[...]:
    def wrapper(*args: Any, **kwargs: Any) -> Any:
        try:
            return func(*args, **kwargs)
        except mysql.connector.Error as e:
            print(f"SQL ERROR: {e}")

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
def insert_data_from_json(cursor: MySQLCursorAbstract, path: os.PathLike[str]) -> None:
    columns, values = None

    with open(path, 'r', encoding="utf-8") as file:
        data = json.load(file)

        try:
            columns = data["columns"]
            values = data["values"]
        except KeyError as e:
            print(e)
            return
    

@error_handling_sql
def execute_sql_script(cursor: MySQLCursorAbstract, path: os.PathLike[str]) -> None:
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
    scripts_path = os.path.join(root_path, "sql", "setup")
    dummy_data_path = os.path.join(root_path, "dummy_data")
    dummy_db = "dummy_demys_db" # change this if the name of the dummy db is changed

    """
        NOTE: The script will look for sql files inside sql/setup, these scripts are only put 
              there if it is necessary for the set up of the website otherwise don't put them
              there.
    """

    scripts = get_files(scripts_path, ".sql")

    conn = mysql.connector.connect(
        host=creds['host'],
        user=creds['user'],
        password=creds['pass']
    )
    cursor = conn.cursor()

    for script in scripts:
        execute_sql_script(cursor, script)

    """
        NOTE: Inside the dummy_data folders there are json files, keep in mind that json file name 
              must be the exact name of their corresponding table names e.g category table must have
              category.json no more no less. The contents of the json file must have two things the
              list of column values and the list of the actual values.
    """

    print(f"Adding dummy data to {dummy_db} ......")

    # the dummy data will be inserted into the dummy db of course
    conn.database = dummy_db

    if not os.path.exists(dummy_data_path):
        exit(f"{dummy_data_path} PATH DOES NOT EXISTS!")

    dummy_data_files = get_files(dummy_data_path, ".json")

    print(f"Found {len(dummy_data_files)} files of dummy data inserting....")

    for file in dummy_data_files:
        insert_data_from_json(cursor, file)


    cursor.close()
    conn.commit()
    conn.close()

if __name__ == "__main__":
    init()