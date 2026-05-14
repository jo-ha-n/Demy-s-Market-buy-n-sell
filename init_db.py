# Set up necessary prequisite dbs for the buy and sell website
import os, sys, subprocess

# Import msql connector, pip install if it does not exists
package = "mysql-connector-python"
try:
    import mysql.connector
except ImportError as e:
    # You also need to add pip to your path in order to automatically add the missing dependency
    # otherwise just manually pip install it
    print(f"Package {package} not foud, Installing {package}.....")
    subprocess.check_call([sys.executable, '-m', 'pip', 'install', package])
finally:
    import mysql.connector



def insert_values(cursor, columns):
    return

def execute_sql_script(path, cursor):
    # Assuming sql command lines ends with a semi colon
    with open(path, encoding="utf-8") as f:
        commands = f.read().split(';')

    for line in commands:
        cursor.execute(line)

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
    scripts_path = os.path.join(root_path, "config")
    dummy_data_path = os.path.join(root_path, "dummy_data")
    dummy_db = "dummy_demys_db" # change this if the name of the dummy db is changed

    scripts = [
        os.path.join(scripts_path, script) for script in ["init_db_dummy.sql", "init_db.sql"] 
    ]

    errors = [
        f"- {s} DOES NOT EXISTS!" for s in scripts if not os.path.isfile(s)
    ]

    if len(errors) > 0:
        print("ERROR:")
        for i in errors: print(f"\t{i}")
        sys.exit("Missing SQL scripts!")

    conn = mysql.connector.connect(
        host=creds['host'],
        user=creds['user'],
        password=creds['pass']
    )
    cursor = conn.cursor()

    for script in scripts:
        execute_sql_script(script, cursor)

    """
        NOTE: Inside the dummy_data folders there are json files, keep in mind that json file name 
              must be the exact name of their corresponding table names e.g category table must have
              category.json no more no less. The contents of the json file must have two things the
              list of column values and the list of the actual values

    """

    print(f"Adding dummy data to {dummy_db} ......")

    # the dummy data will be inserted into the dummy db of course
    conn.database = dummy_db

    cursor.close()
    conn.close()

if __name__ == "__main__":
    init()