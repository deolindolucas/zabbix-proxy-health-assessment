#!/usr/bin/env python3
import argparse
import json
import re
from datetime import datetime, timezone

import boto3
from botocore.exceptions import ClientError


def next_ami_name(ec2, base_name):
    match = re.match(r"^(.*?)(\d+)$", base_name)
    if not match:
        names = [base_name]
    else:
        prefix, suffix = match.group(1), int(match.group(2))
        names = [f"{prefix}{index}" for index in range(suffix, suffix + 100)]

    for name in names:
        images = ec2.describe_images(
            Owners=["self"],
            Filters=[{"Name": "name", "Values": [name]}]
        )["Images"]
        if not images:
            return name

    raise RuntimeError(f"No available AMI name found after {names[0]}")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--region", default="us-east-1")
    parser.add_argument("--instance-id", required=True)
    parser.add_argument("--launch-template-id", required=True)
    parser.add_argument("--ami-name", required=True)
    parser.add_argument("--preflight", action="store_true")
    parser.add_argument("--wait", action="store_true")
    args = parser.parse_args()

    session = boto3.Session(region_name=args.region)
    sts = session.client("sts")
    ec2 = session.client("ec2")

    identity = sts.get_caller_identity()

    instance = ec2.describe_instances(InstanceIds=[args.instance_id])["Reservations"][0]["Instances"][0]
    lt_versions = ec2.describe_launch_template_versions(
        LaunchTemplateId=args.launch_template_id,
        Versions=["$Default", "$Latest"]
    )["LaunchTemplateVersions"]

    if args.preflight:
        print(json.dumps({
            "identity": {
                "account": identity.get("Account"),
                "arn": identity.get("Arn"),
                "user_id": identity.get("UserId"),
            },
            "source_instance": {
                "instance_id": instance.get("InstanceId"),
                "state": instance.get("State", {}).get("Name"),
                "current_image_id": instance.get("ImageId"),
                "public_ip": instance.get("PublicIpAddress"),
                "private_ip": instance.get("PrivateIpAddress"),
            },
            "launch_template": [
                {
                    "version": item.get("VersionNumber"),
                    "default": item.get("DefaultVersion"),
                    "image_id": item.get("LaunchTemplateData", {}).get("ImageId"),
                    "description": item.get("VersionDescription"),
                }
                for item in lt_versions
            ],
            "next_ami_name": next_ami_name(ec2, args.ami_name),
        }, indent=2))
        return

    ami_name = next_ami_name(ec2, args.ami_name)
    created = ec2.create_image(
        InstanceId=args.instance_id,
        Name=ami_name,
        Description=f"Zabbix frontend module assessment image from staging {args.instance_id}",
        NoReboot=True,
        TagSpecifications=[{
            "ResourceType": "image",
            "Tags": [
                {"Key": "Name", "Value": ami_name},
                {"Key": "SourceInstance", "Value": args.instance_id},
                {"Key": "Purpose", "Value": "zabbix-proxy-health-assessment"},
                {"Key": "CreatedBy", "Value": "codex"},
            ],
        }],
    )
    ami_id = created["ImageId"]

    if args.wait:
        ec2.get_waiter("image_available").wait(ImageIds=[ami_id])

    image = ec2.describe_images(ImageIds=[ami_id])["Images"][0]

    new_version = ec2.create_launch_template_version(
        LaunchTemplateId=args.launch_template_id,
        SourceVersion="$Default",
        VersionDescription=f"Use AMI {ami_id} ({ami_name})",
        LaunchTemplateData={"ImageId": ami_id},
    )["LaunchTemplateVersion"]["VersionNumber"]

    modified = ec2.modify_launch_template(
        LaunchTemplateId=args.launch_template_id,
        DefaultVersion=str(new_version),
    )["LaunchTemplate"]

    verify = ec2.describe_launch_template_versions(
        LaunchTemplateId=args.launch_template_id,
        Versions=["$Default", "$Latest"]
    )["LaunchTemplateVersions"]

    print(json.dumps({
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "identity": {
            "account": identity.get("Account"),
            "arn": identity.get("Arn"),
            "user_id": identity.get("UserId"),
        },
        "source_instance": {
            "instance_id": instance.get("InstanceId"),
            "state": instance.get("State", {}).get("Name"),
            "previous_image_id": instance.get("ImageId"),
            "public_ip": instance.get("PublicIpAddress"),
            "private_ip": instance.get("PrivateIpAddress"),
        },
        "launch_template_before": [
            {
                "version": item.get("VersionNumber"),
                "default": item.get("DefaultVersion"),
                "image_id": item.get("LaunchTemplateData", {}).get("ImageId"),
                "description": item.get("VersionDescription"),
            }
            for item in lt_versions
        ],
        "created_ami": {
            "name": ami_name,
            "image_id": ami_id,
            "state": image.get("State"),
            "creation_date": image.get("CreationDate"),
        },
        "new_launch_template_version": new_version,
        "launch_template_modified": {
            "launch_template_id": modified.get("LaunchTemplateId"),
            "default_version": modified.get("DefaultVersionNumber"),
            "latest_version": modified.get("LatestVersionNumber"),
        },
        "launch_template_after": [
            {
                "version": item.get("VersionNumber"),
                "default": item.get("DefaultVersion"),
                "image_id": item.get("LaunchTemplateData", {}).get("ImageId"),
                "description": item.get("VersionDescription"),
            }
            for item in verify
        ],
        "asg_refresh_started": False,
    }, indent=2))


if __name__ == "__main__":
    try:
        main()
    except ClientError as exc:
        print(json.dumps({
            "error": exc.response.get("Error", {}),
            "operation": exc.operation_name,
        }, indent=2))
        raise
